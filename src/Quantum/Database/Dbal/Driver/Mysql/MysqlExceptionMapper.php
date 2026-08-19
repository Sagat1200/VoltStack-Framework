<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Mysql;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind as FK;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\ExceptionMapperInterface;

final class MysqlExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $native, ConnectionInterface $connection, string $stage, ?string $sql = null): DbalException
    {
        $msg = DbalException::redactMessage($native->getMessage());
        $sqlstate = ($native instanceof \PDOException) ? (string)($native->errorInfo[0] ?? $native->getCode()) : (string)$native->getCode();
        $errno = ($native instanceof \PDOException) ? (int)($native->errorInfo[1] ?? 0) : 0;
        $driverMsg  = ($native instanceof \PDOException) ? (string)($native->errorInfo[2] ?? $msg) : $msg;

        $kind = match(true) {
            str_starts_with($sqlstate, '08') => FK::Connectivity,
            in_array($errno, [2002,2003,2006,2013,2055,1040,1053], true) => FK::Connectivity,
            in_array($errno, [1205,1213,1317,3572], true) => FK::Concurrency, // LK timeout / deadlock / query interr / deadlock found
            in_array($errno, [3024,1969], true) => FK::Timeout, // select timeout / stmt timeout
            in_array($errno, [1022,1048,1062,1216,1217,1263,1451,1452,1498,1557,1560,1561,1562,1563,1749,1750,1751,1752,1753,1754,1827,23000,3821,3822,3823,3826,3827,3829,4025], true)
                || str_starts_with($sqlstate, '23')
                => FK::Integrity,
            in_array($errno, [1044,1045,1049,1094,1095,1128,1130,1133,1142,1143,1147,1227,1370,1371,1372,1373,1374,1375,1376,1410,1449,1470,1699,1777,1778,1779,1780,1781,1782,1783,1784,1785,1786,1787,1788,1789,1790,1791,1792,1793,1794,1795,1800,1801,1802,1803,1804,1805,1806,1807,1808,1809,1810,1811,1812,1813,1814,1815,1816,1817,1818,1819,1820,1821,1822,1823,1824,1825,1826,1827,1828,1829,1830,1831,1832,1833,1834,1835,1836,1837,1838,1839,1840,1841,1842,1843,1844,1845,1846,1847,1848,1849,1850,1851,1852,1853,1854,1855,1856,1857,1858,1859,1860,1861,1862,1863,1864,1865,1866,1867,1868,1869,1870,1871,1872,1873,1874,1875,1876,1877,1878,1879,1880,1881,1882,1883,1884,1885,1886,1887,1888,1889,1890,1891,1892,1893,1894,1895,1896,1897,1898,1899,1900], true)
                || str_starts_with($sqlstate, '28') || str_starts_with($sqlstate, '42') && preg_match('/denied|privileges/i', $driverMsg)
                => FK::Authorization,
            str_starts_with($sqlstate, '42')
                || in_array($errno, range(1046, 1064))
                || in_array($errno, range(1100, 1179))
                || in_array($errno, range(1200, 1219))
                => FK::Validation,
            in_array($errno, [1104,1105], true) && str_contains($driverMsg, 'unknown error') => FK::Internal,
            default => FK::Internal,
        };

        $retry = in_array($kind, [FK::Concurrency, FK::Connectivity, FK::Timeout], true)
            || in_array($errno, [1213,2006,2013,2055], true);
        return DbalException::wrap(new \RuntimeException($driverMsg, $errno, $native),
            $kind, $stage, $sql, $retry, extraMsg: "[mysql errno={$errno}, sqlstate={$sqlstate}]");
    }

    public function isRetryable(DbalException $ex): bool { return $ex->retryable; }
}
