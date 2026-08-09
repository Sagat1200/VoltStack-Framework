<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;

/**
 * Recursive descent parser for simple boolean expressions:
 *  - &&  → allOf
 *  - ||  → anyOf
 *  - !   → not
 *  - ( ) → grouping
 *  - identifiers like "role:admin", "permission:write", "owner:true", "guest" → ExpressionTermPolicy
 *
 * Grammar:
 *   expr := orExpr
 *   orExpr := andExpr ('||' andExpr)*
 *   andExpr := notExpr ('&&' notExpr)*
 *   notExpr := '!' notExpr | atom
 *   atom := '(' expr ')' | term
 *   term := [^\s\&\|\!\(\)]+
 */
final class PolicyExpressionResolver
{
    private static ?self $shared = null;

    private static int $termCounter = 0;
    private static int $exprCounter = 0;

    public static function default(): self
    {
        return self::$shared ??= new self();
    }

    public function resolveSingle(string $term): ControllerSecurityPolicyInterface
    {
        $safeTerm = trim($term);
        return new ExpressionTermPolicy($safeTerm, $safeTerm);
    }

    /**
     * @param list<string> $policies
     * @return list<ControllerSecurityPolicyInterface>
     */
    public function resolveList(array $policies): array
    {
        $out = [];
        foreach ($policies as $p) {
            $out[] = $this->resolveOne($p);
        }
        return $out;
    }

    /**
     * String puede ser:
     *   - ID de policy normal (no contiene &&/||/!/ paréntesis) → ExpressionTermPolicy directo
     *   - Expresión con operadores → parse compose composite
     */
    public function resolveOne(string $policy): ControllerSecurityPolicyInterface
    {
        $s = trim($policy);
        if ($s === '') {
            return new NullCompositePolicy(sprintf('expr.empty.%d', self::$termCounter++));
        }
        $hasOperators = preg_match('#(&&|\|\||!|\(|\))#', $s) === 1;
        if (!$hasOperators) {
            return $this->resolveSingle($s);
        }
        return $this->parse($s);
    }

    public function parse(string $expression, ?string $id = null): ControllerSecurityPolicyInterface
    {
        $tokens = $this->tokenize($expression);
        $pos = 0;
        $result = $this->parseOrExpr($tokens, $pos);
        if ($pos < count($tokens)) {
            throw new \InvalidArgumentException(sprintf(
                'Unexpected token "%s" at position %d in expression "%s"',
                $tokens[$pos][1] ?? '?',
                $pos,
                $expression,
            ));
        }
        $useId = $id ?? $expression;
        if ($useId !== null && $useId !== '' && $useId !== $result->id()) {
            if ($result instanceof CompositePolicy) {
                try {
                    $refl = new \ReflectionProperty($result, 'children');
                    $refl->setAccessible(true);
                    $children = $refl->isInitialized($result) ? $refl->getValue($result) : [];
                } catch (\Throwable) {
                    $children = [];
                }
                $underlying = $result;
                if ($underlying instanceof AnyOfPolicy) {
                    $result = new AnyOfPolicy($useId, is_array($children) ? $children : []);
                } elseif ($underlying instanceof AllOfPolicy) {
                    $result = new AllOfPolicy($useId, is_array($children) ? $children : []);
                } elseif ($underlying instanceof NotPolicy) {
                    $result = new NotPolicy($useId, is_array($children) ? $children : []);
                } elseif ($underlying instanceof WeightedVotingPolicy) {
                    $result = new AnyOfPolicy($useId, is_array($children) ? $children : []);
                } else {
                    $result = new AnyOfPolicy($useId, is_array($children) ? $children : []);
                }
            } elseif ($result instanceof ExpressionTermPolicy) {
                try {
                    $refl = new \ReflectionProperty($result, 'term');
                    $refl->setAccessible(true);
                    $term = $refl->isInitialized($result) ? $refl->getValue($result) : '';
                } catch (\Throwable) {
                    $term = '';
                }
                $result = new ExpressionTermPolicy($useId, (string)$term);
            }
        }
        return $result;
    }

    /**
     * @return list<array{0:string,1:string}> tokens: type, value
     */
    private function tokenize(string $expr): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($expr);
        while ($i < $len) {
            $c = $expr[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '&' && $i + 1 < $len && $expr[$i + 1] === '&') {
                $tokens[] = ['OP_AND', '&&'];
                $i += 2;
                continue;
            }
            if ($c === '|' && $i + 1 < $len && $expr[$i + 1] === '|') {
                $tokens[] = ['OP_OR', '||'];
                $i += 2;
                continue;
            }
            if ($c === '!') {
                $tokens[] = ['OP_NOT', '!'];
                $i++;
                continue;
            }
            if ($c === '(') {
                $tokens[] = ['LPAREN', '('];
                $i++;
                continue;
            }
            if ($c === ')') {
                $tokens[] = ['RPAREN', ')'];
                $i++;
                continue;
            }
            $start = $i;
            while ($i < $len && !ctype_space($expr[$i]) && !in_array($expr[$i], ['&', '|', '!', '(', ')'], true)) {
                $i++;
            }
            if ($i > $start) {
                $tokens[] = ['TERM', substr($expr, $start, $i - $start)];
            }
        }
        return $tokens;
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     */
    private function parseOrExpr(array $tokens, int &$pos): ControllerSecurityPolicyInterface
    {
        $left = $this->parseAndExpr($tokens, $pos);
        $children = [$left];
        while ($pos < count($tokens) && $tokens[$pos][0] === 'OP_OR') {
            $pos++;
            $children[] = $this->parseAndExpr($tokens, $pos);
        }
        if (count($children) === 1) {
            return $left;
        }
        return new AnyOfPolicy(sprintf('composite.expr.or.%d', self::$exprCounter++), $children);
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     */
    private function parseAndExpr(array $tokens, int &$pos): ControllerSecurityPolicyInterface
    {
        $left = $this->parseNotExpr($tokens, $pos);
        $children = [$left];
        while ($pos < count($tokens) && $tokens[$pos][0] === 'OP_AND') {
            $pos++;
            $children[] = $this->parseNotExpr($tokens, $pos);
        }
        if (count($children) === 1) {
            return $left;
        }
        return new AllOfPolicy(sprintf('composite.expr.and.%d', self::$exprCounter++), $children);
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     */
    private function parseNotExpr(array $tokens, int &$pos): ControllerSecurityPolicyInterface
    {
        if ($pos < count($tokens) && $tokens[$pos][0] === 'OP_NOT') {
            $pos++;
            $inner = $this->parseNotExpr($tokens, $pos);
            return new NotPolicy(sprintf('composite.expr.not.%d', self::$exprCounter++), [$inner]);
        }
        return $this->parseAtom($tokens, $pos);
    }

    /**
     * @param list<array{0:string,1:string}> $tokens
     */
    private function parseAtom(array $tokens, int &$pos): ControllerSecurityPolicyInterface
    {
        if ($pos >= count($tokens)) {
            throw new \InvalidArgumentException('Unexpected end of expression (expected atom or parenthesized group)');
        }
        if ($tokens[$pos][0] === 'LPAREN') {
            $pos++;
            $inner = $this->parseOrExpr($tokens, $pos);
            if ($pos >= count($tokens) || $tokens[$pos][0] !== 'RPAREN') {
                throw new \InvalidArgumentException(sprintf(
                    'Missing closing parenthesis after position %d; got "%s"',
                    $pos,
                    $tokens[$pos][1] ?? '<EOF>',
                ));
            }
            $pos++;
            return $inner;
        }
        if ($tokens[$pos][0] === 'TERM') {
            $value = $tokens[$pos][1];
            $pos++;
            return $this->resolveSingle($value);
        }
        throw new \InvalidArgumentException(sprintf(
            'Unexpected token "%s" (type %s); expected term or parenthesized group',
            $tokens[$pos][1],
            $tokens[$pos][0],
        ));
    }
}
