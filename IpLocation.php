<?php
namespace Jazor;

class IpLocation
{
    private static string $database = __DIR__ . '/qqwry.dat';

    public static function setDatabase(string $path)
    {
        self::$database = $path;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public static function version()
    {
        $version = self::getLocation('255.255.255.255');

        if (empty($version))
            throw new \Exception('can not read version info');

        if (!preg_match('/(\d+)年(\d+)月(\d+)日/', $version, $match))
            throw new \Exception('can not find version info');

        return $match[0];
    }

    /**
     * get the location of a single ip address
     * @param $ip
     * @param string $charset
     * @return string
     * @throws \Exception
     */
    public static function getLocation($ip, string $charset = 'utf-8'): string
    {
        if(empty($ip) || !is_string($ip)){
            throw new \Exception('do not support multiple ip, please use "getLocationDetail" method to read multiple ip.');
        }
        $result = self::getLocationDetail($ip, $charset);
        return $result['location'];
    }

    /**
     * get the location detail information of one/many ip address.
     * @param string|int|array $ip
     * @param string $charset
     * @return array
     * @throws \Exception
     */
    public static function getLocationDetail($ip, string $charset = 'utf-8'): array
    {
        if (!$fd = @fopen(self::$database, 'rb')) {
            throw new \Exception('IP data file not exists or access denied');
        }
        try {
            if (!is_array($ip)) return self::getDetail($fd, $ip, $charset);

            $results = [];
            foreach ($ip as $ip_) {
                $results[] = self::getDetail($fd, $ip_, $charset);;
            }

            return $results;
        } finally {
            @fclose($fd);
        }
    }

    /**
     * @throws \Exception
     */
    private static function getDetail($fd, $ip, $charset): array
    {
        fseek($fd, 0);
        $ipNum = is_numeric($ip) ? $ip : ip2long($ip);

        $result = self::search($fd, $ipNum);

        if ($charset != 'gb18030') {
            $result['location'] = iconv('gb18030', $charset, $result['location']);
            $result['country'] = iconv('gb18030', $charset, $result['country']);
            $result['area'] = iconv('gb18030', $charset, $result['area']);
        }

        $result['ip'] = long2ip($ipNum);

        return $result;
    }


    /**
     * @param $fd
     * @param int $len
     * @return float|int|string
     * @throws \Exception
     */
    private static function readLong($fd, int $len = 4)
    {
        $data = fread($fd, $len);
        if (strlen($data) != $len) throw new \Exception('data file error');
        if (strlen($data) != 4) $data .= chr(0);
        $ip = unpack('V', $data)[1];
        if ($ip < 0) $ip += pow(2, 32);
        return $ip;
    }

    /**
     * @param $fd
     * @return string
     */
    private static function readString($fd): string
    {
        $str = '';
        while (($char = fread($fd, 1)) != chr(0))
            $str .= $char;
        return $str;
    }

    /**
     * @param $fd
     * @param int $seek
     * @return int
     * @throws \Exception
     */
    private static function readByte($fd, int $seek = -1): int
    {
        if ($seek > -1) fseek($fd, $seek);
        $byte = fread($fd, 1);
        if ($byte === false || strlen($byte) == 0) throw new \Exception('end of file');
        return ord($byte[0]);
    }

    /**
     * @param $fd
     * @return string[]
     * @throws \Exception
     */
    private static function readLocation($fd): array
    {
        $flag = self::readByte($fd);
        if ($flag === 1) {
            fseek($fd, self::readLong($fd, 3));
            $flag = 0;
        }
        $country = self::readRegion($fd, $flag, $countryOffset);
        $area = self::readRegion($fd, 0, $areaOffset);

        if ($countryOffset > 0) {
            fseek($fd, $countryOffset);
            $country = self::readString($fd);
        }
        if ($areaOffset > 0) {
            fseek($fd, $areaOffset);
            $area = self::readString($fd);
        }

        return [$country, $area];
    }

    /**
     * @param $fd
     * @param $flag
     * @param $regionOffset
     * @return string
     * @throws \Exception
     */
    private static function readRegion($fd, $flag, &$regionOffset): ?string
    {
        $regionOffset = 0;
        $flag = $flag ?: self::readByte($fd);
        if ($flag !== 2) {
            fseek($fd, -1, SEEK_CUR);

            /**
             * ensure the position is the beginning of next record
             * */
            return self::readString($fd);
        }
        $regionOffset = self::readLong($fd, 3);

        return null;
    }


    /**
     * @param $file
     * @param \Closure|null $every
     * @throws \Exception
     */
    public static function saveTo($file, \Closure $every = null)
    {
        if (!$fd = @fopen(self::$database, 'rb')) {
            throw new \Exception('IP data file not exists or access denied');
        }
        try {
            $min = self::readLong($fd);
            $max = self::readLong($fd);

            $output = fopen($file, 'wb');
            try {
                for ($i = $min; $i <= $max; $i += 7) {
                    fseek($fd, $i);
                    $ip_start = self::readLong($fd);
                    fseek($fd, self::readLong($fd, 3));
                    $ip_end = self::readLong($fd);
                    $detail = self::readLocation($fd);
                    $addr = implode("\t", $detail);
                    fwrite($output, $every !== null ? $every($ip_start, $ip_end, $addr) : sprintf("%s\t%s\t%s\r\n", $ip_start, $ip_end, $addr));
                }
                fflush($output);
            } finally {
                @fclose($output);
            }
        } finally {
            @fclose($fd);
        }
    }

    /**
     * @param $fd
     * @param $search
     * @return array
     * @throws \Exception
     */
    private static function search($fd, $search): array
    {

        $min = self::readLong($fd);
        $max = self::readLong($fd);
        $amount = ($max - $min) / 7 + 1;

        $start = 0;
        $end = $amount;

        while (true) {
            $index = intval(($end + $start) / 2);

            fseek($fd, $min + 7 * $index);
            $ip_start = self::readLong($fd);

            if ($ip_start > $search) {
                $end = $index;
                continue;
            }

            fseek($fd, self::readLong($fd, 3));
            $ip_end = self::readLong($fd);

            if ($ip_end >= $search) break;

            if ($index == $start) throw new \Exception('Unknown Ip');

            $start = $index;
        }

        list($country, $area) = self::readLocation($fd);

        $country = trim($country);
        $area = trim($area);

        $location = "$country $area";

        return [
            'start' => long2ip($ip_start),
            'end' => long2ip($ip_end),
            'location' => $location,
            'country' => $country,
            'area' => $area
        ];
    }
}

