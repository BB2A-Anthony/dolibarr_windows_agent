<?php
/**
 * ISP detection and logo helper.
 */
class SysteminfoIspLogo
{
    /**
     * Known ISPs: pattern matched (case-insensitive, accent-insensitive)
     * against the 'org' string => logo file in img/isp/.
     *
     * @var array
     */
    protected static $isps = array(
        'orange'    => '/orange/i',
        'free'      => '/free\s*(sas|s\.a\.s)?|proxad|iliad/i',
        'sfr'       => '/sfr|altice/i',
        'bouygues'  => '/bouygues|bbox/i',
        'numericable' => '/numericable/i',
        'walt'      => '/walt/i',
        'virgin'    => '/virgin/i',
        'ovh'       => '/ovh|ovhcloud|kimsufi/i',
        'scaleway'  => '/scaleway/i',
        'amazon'    => '/amazon|aws/i',
        'google'    => '/google/i',
        'microsoft' => '/microsoft|azure/i',
        'hetzner'   => '/hetzner/i',
        'contabo'   => '/contabo/i',
        'adeli'     => '/adeli/i',
        'axione'    => '/axione/i',
        'covage'    => '/covage/i',
    );

    /**
     * Detect the ISP key from the raw 'org' string.
     *
     * @param  string $isp Raw ISP string (e.g. 'AS1234 Orange')
     * @return string|null ISP key matching a logo, null otherwise
     */
    public static function detect($isp)
    {
        if (empty($isp)) {
            return null;
        }
        foreach (self::$isps as $key => $pattern) {
            if (preg_match($pattern, $isp)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Return the logo URL for the ISP, or a generic fallback.
     *
     * @param  string $isp Raw ISP string
     * @return string Relative URL of the logo image
     */
    public static function logoUrl($isp)
    {
        $key = self::detect($isp);
        $base = DOL_MAIN_URL_ROOT . '/custom/systeminfo/img/isp/';
        if ($key) {
            foreach (array('svg', 'png') as $ext) {
                if (file_exists(DOL_DOCUMENT_ROOT . '/custom/systeminfo/img/isp/' . $key . '.' . $ext)) {
                    return $base . $key . '.' . $ext;
                }
            }
        }
        return DOL_MAIN_URL_ROOT . '/theme/common/object_company.png';
    }

    /**
     * Return a human readable ISP name (without AS number).
     *
     * @param  string $isp Raw ISP string
     * @return string
     */
    public static function cleanName($isp)
    {
        if (empty($isp)) {
            return '';
        }
        $clean = preg_replace('/^AS\d+\s*/i', '', trim($isp));
        return $clean ? $clean : $isp;
    }
}
