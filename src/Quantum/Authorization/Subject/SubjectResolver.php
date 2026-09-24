<?php

declare(strict_types=1);

namespace Quantum\Authorization\Subject;

use Quantum\Authorization\Contracts\SubjectResolverInterface;

final class SubjectResolver implements SubjectResolverInterface
{
    public function describe(mixed $subject = null): SubjectDescriptor
    {
        if ($subject === null) {
            return new SubjectDescriptor(SubjectType::None);
        }

        if (is_string($subject) && class_exists($subject)) {
            return new SubjectDescriptor(SubjectType::ClassName, $subject, $subject);
        }

        if (is_object($subject)) {
            return new SubjectDescriptor(SubjectType::Object, $subject, $subject::class);
        }

        return new SubjectDescriptor(SubjectType::Scalar, $subject, get_debug_type($subject));
    }
}
