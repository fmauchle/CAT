<?php

/*
 * ******************************************************************************
 * Copyright 2011-2017 DANTE Ltd. and GÉANT on behalf of the GN3, GN3+, GN4-1 
 * and GN4-2 consortia
 *
 * License: see the web/copyright.php file in the file structure
 * ******************************************************************************
 */

/**
 * This is the collection of methods dedicated for the user GUI
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @package UserAPI
 *
 * Parts of this code are based on simpleSAMLPhp discojuice module.
 * This product includes GeoLite data created by MaxMind, available from
 * http://www.maxmind.com
 */

namespace core;

use GeoIp2\Database\Reader;
use \Exception;

/**
 * The basic methoods for the user GUI
 * @package UserAPI
 *
 */
class UserAPI extends CAT {

    /**
     * nothing special to be done here.
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Prepare the device module environment and send back the link
     * This method creates a device module instance via the {@link DeviceFactory} call, 
     * then sets up the device module environment for the specific profile by calling 
     * {@link DeviceConfig::setup()} method and finally, called the devide writeInstaller meethod
     * passing the returned path name.
     * 
     * @param string $device identifier as in {@link devices.php}
     * @param int $profileId profile identifier
     *
     * @return array 
     *  array with the following fields: 
     *  profile - the profile identifier; 
     *  device - the device identifier; 
     *  link - the path name of the resulting installer
     *  mime - the mimetype of the installer
     */
    public function generateInstaller($device, $profileId, $generatedFor = "user", $token = NULL, $password = NULL) {
        $this->languageInstance->setTextDomain("devices");
        $this->loggerInstance->debug(4, "installer:$device:$profileId\n");
        $validator = new \web\lib\common\InputValidation();
        $profile = $validator->Profile($profileId);
        // test if the profile is production-ready and if not if the authenticated user is an owner
        if ($this->verifyDownloadAccess($profile) === FALSE) {
            return;
        }
        $installerProperties = [];
        $installerProperties['profile'] = $profileId;
        $installerProperties['device'] = $device;
        $cache = $this->getCache($device, $profile);
        $this->installerPath = $cache['path'];
        if ($this->installerPath && $token == NULL && $password == NULL) {
            $this->loggerInstance->debug(4, "Using cached installer for: $device\n");
            $installerProperties['link'] = "API.php?action=downloadInstaller&lang=" . $this->languageInstance->getLang() . "&profile=$profileId&device=$device&generatedfor=$generatedFor";
            $installerProperties['mime'] = $cache['mime'];
        } else {
            $myInstaller = $this->generateNewInstaller($device, $profile, $generatedFor, $token, $password);
            if ($myInstaller['link'] !== 0) {
                $installerProperties['mime'] = $myInstaller['mime'];
            }
            $installerProperties['link'] = $myInstaller['link'];
        }
        $this->languageInstance->setTextDomain("web_user");
        return($installerProperties);
    }
    
    private function verifyDownloadAccess($profile) {
        $attribs = $profile->getCollapsedAttributes();
        if (!isset($attribs['profile:production']) || (isset($attribs['profile:production']) && $attribs['profile:production'][0] != "on")) {
            $this->loggerInstance->debug(4, "Attempt to download a non-production ready installer for profile: $profile->identifier\n");
            $auth = new \web\lib\admin\Authentication();
            if (!$auth->isAuthenticated()) {
                $this->loggerInstance->debug(2, "User NOT authenticated, rejecting request for a non-production installer\n");
                header("HTTP/1.0 403 Not Authorized");
                return(FALSE);
            }
            $userObject = new User($_SESSION['user']);
            if (!$userObject->isIdPOwner($profile->institution)) {
                $this->loggerInstance->debug(2, "User not an owner of a non-production profile - access forbidden\n");
                header("HTTP/1.0 403 Not Authorized");
                return(FALSE);
            }
            $this->loggerInstance->debug(4, "User is the owner - allowing access\n");
        }
        return(TRUE);
    }

    /**
     * This function tries to find a cached copy of an installer for a given
     * combination of Profile and device
     * @param string $device
     * @param AbstractProfile $profile
     * @return boolean|string the string with the path to the cached copy, or FALSE if no cached copy exists
     */
    private function getCache($device, $profile) {
        $deviceList = \devices\Devices::listDevices();
        $deviceConfig = $deviceList[$device];
        $noCache = (isset(\devices\Devices::$Options['no_cache']) && \devices\Devices::$Options['no_cache']) ? 1 : 0;
        if (isset($deviceConfig['options']['no_cache'])) {
            $noCache = $deviceConfig['options']['no_cache'] ? 1 : 0;
        }
        if ($noCache) {
            $this->loggerInstance->debug(5, "getCache: the no_cache option set for this device\n");
            return(FALSE);
        }
        $this->loggerInstance->debug(5, "getCache: caching option set for this device\n");
        $cache = $profile->testCache($device);
        $iPath = $cache['cache'];
        if ($iPath && is_file($iPath)) {
            return(['path' => $iPath, 'mime' => $cache['mime']]);
        }
        return(FALSE);
    }

    /**
     * Generates a new installer for the given combination of device and Profile
     * 
     * @param string $device
     * @param AbstractProfile $profile
     * @return array info about the new installer (mime and link)
     */
    private function generateNewInstaller($device, $profile, $generatedFor, $token, $password) {
        $this->loggerInstance->debug(5, "generateNewInstaller() - Enter");
        $factory = new DeviceFactory($device);
        $this->loggerInstance->debug(5, "generateNewInstaller() - created Device");
        $dev = $factory->device;
        $out = [];
        if (isset($dev)) {
            $dev->setup($profile, $token, $password);
            $this->loggerInstance->debug(5, "generateNewInstaller() - Device setup done");
            $installer = $dev->writeInstaller();
            $this->loggerInstance->debug(5, "generateNewInstaller() - writeInstaller complete");
            $iPath = $dev->FPATH . '/tmp/' . $installer;
            if ($iPath && is_file($iPath)) {
                if (isset($dev->options['mime'])) {
                    $out['mime'] = $dev->options['mime'];
                } else {
                    $info = new \finfo();
                    $out['mime'] = $info->file($iPath, FILEINFO_MIME_TYPE);
                }
                $this->installerPath = $dev->FPATH . '/' . $installer;
                rename($iPath, $this->installerPath);
                $integerEap = (new \core\common\EAP($dev->selectedEap))->getIntegerRep();
                $profile->updateCache($device, $this->installerPath, $out['mime'], $integerEap);
                if (CONFIG['DEBUG_LEVEL'] < 4) {
                    \core\common\Entity::rrmdir($dev->FPATH . '/tmp');
                }
                $this->loggerInstance->debug(4, "Generated installer: " . $this->installerPath . ": for: $device, EAP:" . $integerEap . "\n");
                $out['link'] = "API.php?action=downloadInstaller&lang=" . $this->languageInstance->getLang() . "&profile=" . $profile->identifier . "&device=$device&generatedfor=$generatedFor";
            } else {
                $this->loggerInstance->debug(2, "Installer generation failed for: " . $profile->identifier . ":$device:" . $this->languageInstance->getLang() . "\n");
                $out['link'] = 0;
            }
        }
        return($out);
    }

    /**
     * interface to Devices::listDevices() 
     */
    public function listDevices($showHidden = 0) {
        $dev = \devices\Devices::listDevices();
        $returnList = [];
        $count = 0;
        if ($showHidden !== 0 && $showHidden != 1) {
            throw new Exception("show_hidden is only be allowed to be 0 or 1, but it is $showHidden!");
        }
        foreach ($dev as $device => $deviceProperties) {
            if (isset($deviceProperties['options']['hidden']) && $deviceProperties['options']['hidden'] && $showHidden == 0) {
                continue;
            }
            $count++;

            $deviceProperties['device'] = $device;

            $group = isset($deviceProperties['group']) ? $deviceProperties['group'] : 'other';
            if (!isset($returnList[$group])) {
                $returnList[$group] = [];
            }
            $returnList[$group][$device] = $deviceProperties;
        }
        return $returnList;
    }

    /**
     * 
     * @param string $device
     * @param int $profileId
     */
    public function deviceInfo($device, $profileId) {
        $this->languageInstance->setTextDomain("devices");
        $validator = new \web\lib\common\InputValidation();
        $out = 0;
        $profile = $validator->Profile($profileId);
        $factory = new DeviceFactory($device);
        $dev = $factory->device;
        if (isset($dev)) {
            $dev->setup($profile);
            $out = $dev->writeDeviceInfo();
        }
        $this->languageInstance->setTextDomain("web_user");
        echo $out;
    }

    /**
     * Prepare the support data for a given profile
     *
     * @param int $profId profile identifier
     * @return array
     * array with the following fields:
     * - local_email
     * - local_phone
     * - local_url
     * - description
     * - devices - an array of device names and their statuses (for a given profile)
     */
    public function profileAttributes($profId) {
        $this->languageInstance->setTextDomain("devices");
        $validator = new \web\lib\common\InputValidation();
        $profile = $validator->Profile($profId);
        $attribs = $profile->getCollapsedAttributes();
        $returnArray = [];
        $returnArray['silverbullet'] = $profile instanceof ProfileSilverbullet ? 1 : 0;
        if (isset($attribs['support:email'])) {
            $returnArray['local_email'] = $attribs['support:email'][0];
        }
        if (isset($attribs['support:phone'])) {
            $returnArray['local_phone'] = $attribs['support:phone'][0];
        }
        if (isset($attribs['support:url'])) {
            $returnArray['local_url'] = $attribs['support:url'][0];
        }
        if (isset($attribs['profile:description'])) {
            $returnArray['description'] = $attribs['profile:description'][0];
        }
        $returnArray['devices'] = $profile->listDevices();
        $this->languageInstance->setTextDomain("web_user");
        return($returnArray);
    }

    /**
      this method needs to be used with care, it could give wrong results in some
      cicumstances
     */
    protected function getRootURL() {
        $backtrace = debug_backtrace();
        $backtraceFileInfo = array_pop($backtrace);
        $fileTemp = $backtraceFileInfo['file'];
        $file = substr($fileTemp, strlen(dirname(__DIR__)));
        if ($file === FALSE) {
            throw new Exception("No idea what's going wrong - filename cropping returned FALSE!");
        }
        while (substr($file, 0, 1) == '/') {
            $file = substr($file, 1);
            if ($file === FALSE) {
                throw new Exception("Unable to crop leading / from a string known to start with / ???");
            }
        }
        $slashCount = count(explode('/', $file));
        $out = $_SERVER['SCRIPT_NAME'];
        for ($iterator = 0; $iterator < $slashCount; $iterator++) {
            $out = dirname($out);
        }
        if ($out == '/') {
            $out = '';
        }
        return '//' . $_SERVER['SERVER_NAME'] . $out;
    }

    /**
     * Generate and send the installer
     *
     * @param string $device identifier as in {@link devices.php}
     * @param int $prof_id profile identifier
     * @return string binary stream: installerFile
     */
    public function downloadInstaller($device, $prof_id, $generated_for = 'user', $token = NULL, $password = NULL) {
        $this->loggerInstance->debug(4, "downloadInstaller arguments: $device,$prof_id,$generated_for\n");
        $output = $this->generateInstaller($device, $prof_id, $generated_for, $token, $password);
        $this->loggerInstance->debug(4, "output from GUI::generateInstaller:");
        $this->loggerInstance->debug(4, print_r($output, true));
        if (empty($output['link']) || $output['link'] === 0) {
            header("HTTP/1.0 404 Not Found");
            return;
        }
        $validator = new \web\lib\common\InputValidation();
        $profile = $validator->Profile($prof_id);
        $profile->incrementDownloadStats($device, $generated_for);
        $file = $this->installerPath;
        $filetype = $output['mime'];
        $this->loggerInstance->debug(4, "installer MIME type:$filetype\n");
        header("Content-type: " . $filetype);
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        ob_clean();
        flush();
        readfile($file);
    }

    /**
     * resizes image files
     * 
     * @param string $inputImage
     * @param string $destFile
     * @param int $width
     * @param int $height
     * @param bool $resize shall we do resizing? width and height are ignored otherwise
     * @return array
     */
    private function processImage($inputImage, $destFile, $width, $height, $resize) {
        $info = new \finfo();
        $filetype = $info->buffer($inputImage, FILEINFO_MIME_TYPE);
        $offset = 60 * 60 * 24 * 30;
        // gmdate cannot fail here - time() is its default argument (and integer), and we are adding an integer to it
        $expiresString = "Expires: " . /** @scrutinizer ignore-type */ gmdate("D, d M Y H:i:s", time() + $offset) . " GMT";
        $blob = $inputImage;

        if ($resize === TRUE) {
            $image = new \Imagick();
            $image->readImageBlob($inputImage);
            $image->setImageFormat('PNG');
            $image->thumbnailImage($width, $height, 1);
            $blob = $image->getImageBlob();
            $this->loggerInstance->debug(4, "Writing cached logo $destFile for IdP/Federation.\n");
            file_put_contents($destFile, $blob);
        }

        return ["filetype" => $filetype, "expires" => $expiresString, "blob" => $blob];
    }

    /**
     * Get and prepare logo file 
     *
     * When called for DiscoJuice, first check if file cache exists
     * If not then generate the file and save it in the cache
     * @param int $idp IdP identifier
     * @param int $width maximum width of the generated image - if 0 then it is treated as no upper bound
     * @param int $height  maximum height of the generated image - if 0 then it is treated as no upper bound
     * @return array|null array with image information or NULL if there is no logo
     */
    protected function getLogo($identifier, $type, $width = 0, $height = 0) {
        $expiresString = '';
        $resize = FALSE;
        $logoFile = "";
        $attributeName = "";
        $validator = new \web\lib\common\InputValidation();
        switch ($type) {
            case "federation":
                if (is_int($identifier)) {
                    throw new Exception("Federation identifiers are strings!");
                }
                $entity = $validator->Federation($identifier);
                $attributeName = "fed:logo_file";
                break;
            case "idp":
                if (!is_int($identifier)) {
                    throw new Exception("Institution identifiers are integers!");
                }
                $entity = $validator->IdP($identifier);
                $attributeName = "general:logo_file";
                break;
            default:
                throw new Exception("Unknown type of logo requested!");
                return;
                break;
        }
        $filetype = 'image/png'; // default, only one code path where it can become different
        if (($width || $height) && is_numeric($width) && is_numeric($height)) {
            $resize = TRUE;
            if ($height == 0) {
                $height = 10000;
            }
            if ($width == 0) {
                $width = 10000;
            }
            $logoFile = ROOT . '/web/downloads/logos/' . $identifier . '_' . $width . '_' . $height . '.png';
        }
        if ($resize === TRUE && is_file($logoFile)) {
            $this->loggerInstance->debug(4, "Using cached logo $logoFile for: $identifier\n");
            $blob = file_get_contents($logoFile);
        } else {
            $logoAttribute = $entity->getAttributes($attributeName);
            if (count($logoAttribute) == 0) {
                return(NULL);
            }
            $this->loggerInstance->debug(4,"RESIZE:$width:$height\n");
            $meta = $this->processImage($logoAttribute[0]['value'], $logoFile, $width, $height, $resize);
            $filetype = $meta['filetype'];
            $expiresString = $meta['expires'];
            $blob = $meta['blob'];
        }
        return ["filetype" => $filetype, "expires" => $expiresString, "blob" => $blob];
    }
   
    /**
     * outputs a logo
     * 
     * @param string|int $identifier
     * @param string $type "federation" or "idp"
     * @param int $width
     * @param int $height
     */
    public function sendLogo($identifier, $type, $width = 0, $height = 0) {
        $logo = $this->getLogo($identifier, $type, $width, $height);
        header("Content-type: " . $logo['filetype']);
        header("Cache-Control:max-age=36000, must-revalidate");
        header($logo['expires']);
        echo $logo['blob'];
    }

    
    
    /**
     * find out where the user is currently located
     * @return array
     */
    public function locateUser() {
        $geoipVersion = CONFIG['GEOIP']['version'] ?? 0;
        switch ($geoipVersion) {
            case 0:
                return(['status' => 'error', 'error' => 'Geolocation not supported']);
                break;
            case 1:
                return($this->locateUser1());
                break;
            case 2:
                return($this->locateUser2());
                break;
            default:
                throw new Exception("This version of GeoIP is not known!");
        }
    }
    
    private function locateUser1() {
        if (CONFIG['GEOIP']['version'] != 1) {
            return ['status' => 'error', 'error' => 'Function for GEOIPv1 called, but config says this is not the version to use!'];
        }
        //$host = $_SERVER['REMOTE_ADDR'];
        $host = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
        $record = geoip_record_by_name($host);
        if ($record === FALSE) {
            return ['status' => 'error', 'error' => 'Problem listing countries'];
        }
        $result = ['status' => 'ok'];
        $result['country'] = $record['country_code'];
//  the two lines below are a dirty hack to take of the error in naming the UK federation
        if ($result['country'] == 'GB') {
            $result['country'] = 'UK';
        }
        $result['region'] = $record['region'];
        $result['geo'] = ['lat' => (float) $record['latitude'], 'lon' => (float) $record['longitude']];
        return($result);
    }
    
    /**
     * find out where the user is currently located, using GeoIP2
     * @return array
     */
    private function locateUser2() {
        if (CONFIG['GEOIP']['version'] != 2) {
            return ['status' => 'error', 'error' => 'Function for GEOIPv2 called, but config says this is not the version to use!'];
        }
        require_once CONFIG['GEOIP']['geoip2-path-to-autoloader'];
        $reader = new Reader(CONFIG['GEOIP']['geoip2-path-to-db']);
        $host = $_SERVER['REMOTE_ADDR'];
        try {
            $record = $reader->city($host);
        } catch (\Exception $e) {
            $result = ['status' => 'error', 'error' => 'Problem listing countries'];
            return($result);
        }
        $result = ['status' => 'ok'];
        $result['country'] = $record->country->isoCode;
//  the two lines below are a dirty hack to take of the error in naming the UK federation
        if ($result['country'] == 'GB') {
            $result['country'] = 'UK';
        }
        $result['region'] = $record->continent->name;

        $result['geo'] = ['lat' => (float) $record->location->latitude, 'lon' => (float) $record->location->longitude];
        return($result);
    }

    /**
     * Calculate the distance in km between two points given their
     * geo coordinates.
     * @param array $point1 - first point as an 'lat', 'lon' array 
     * @param array $profile1 - second point as an 'lat', 'lon' array 
     * @return float distance in km
     */
    private function geoDistance($point1, $profile1) {

        $distIntermediate = sin(deg2rad($point1['lat'])) * sin(deg2rad($profile1['lat'])) +
                cos(deg2rad($point1['lat'])) * cos(deg2rad($profile1['lat'])) * cos(deg2rad($point1['lon'] - $profile1['lon']));
        $dist = rad2deg(acos($distIntermediate)) * 60 * 1.1852;
        return(round($dist));
    }

    /**
     * Order active identity providers according to their distance and name
     * @param array $currentLocation - current location
     * @return array $IdPs -  list of arrays ('id', 'name');
     */
    public function orderIdentityProviders($country, $currentLocation = NULL) {
        $idps = $this->listAllIdentityProviders(1, $country);

        if (is_null($currentLocation)) {
            $currentLocation = ['lat' => "90", 'lon' => "0"];
            $userLocation = $this->locateUser();
            if ($userLocation['status'] == 'ok') {
                $currentLocation = $userLocation['geo'];
            }
        }
        $idpTitle = [];
        $resultSet = [];
        foreach ($idps as $idp) {
            $idpTitle[$idp['entityID']] = $idp['title'];
            $dist = 10000;
            if (isset($idp['geo'])) {
                $G = $idp['geo'];
                if (isset($G['lon'])) {
                    $d1 = $this->geoDistance($currentLocation, $G);
                    if ($d1 < $dist) {
                        $dist = $d1;
                    }
                } else {
                    foreach ($G as $g) {
                        $d1 = $this->geoDistance($currentLocation, $g);
                        if ($d1 < $dist) {
                            $dist = $d1;
                        }
                    }
                }
            }
            if ($dist > 100) {
                $dist = 10000;
            }
            $d = sprintf("%06d", $dist);
            $resultSet[$idp['entityID']] = $d . " " . $idp['title'];
        }
        asort($resultSet);
        $outarray = [];
        foreach (array_keys($resultSet) as $r) {
            $outarray[] = ['idp' => $r, 'title' => $idpTitle[$r]];
        }
        return($outarray);
    }

    /**
     * Detect the best device driver form the browser
     *
     * Detects the operating system and returns its id 
     * display name and group membership (as in devices.php)
     * @return array|false OS information, indexed by 'id', 'display', 'group'
     */
    public function detectOS() {
        $oldDomain = $this->languageInstance->setTextDomain("devices");
        $Dev = \devices\Devices::listDevices();
        $this->languageInstance->setTextDomain($oldDomain);
        $devId = $this->deviceFromRequest();
        if ($devId !== FALSE) {
            $ret = $this->returnDevice($devId, $Dev[$devId]);
            if ($ret !== FALSE) {
                return($ret);
            } 
        }
// the device has not been specified or not specified correctly, try to detect if from the browser ID
        $browser = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_SANITIZE_STRING);
        $this->loggerInstance->debug(4, "HTTP_USER_AGENT=$browser\n");
        foreach ($Dev as $devId => $device) {
            if (!isset($device['match'])) {
                continue;
            }
            if (preg_match('/' . $device['match'] . '/', $browser)) {
                return ($this->returnDevice($devId, $device));
            }
        }
        $this->loggerInstance->debug(2, "Unrecognised system: $browser\n");
        return(false);
    }
    
    /*
     * test if devise is defined and is not hidden. If all is fine return extracted information.
     * Return FALSE if the device has not been correctly specified
     */
    private function returnDevice($devId, $device) {
        if (!isset($device['options']['hidden']) || $device['options']['hidden'] == 0) {
            $this->loggerInstance->debug(4, "Browser_id: $devId\n");
            return(['device' => $devId, 'display' => $device['display'], 'group' => $device['group']]);
        }
        return(FALSE);
    }
   
    /**
     * This methods cheks if the devide has been specified as the HTTP parameters
     * @return device id is correcty specified or FALSE otherwise
     */
    private function deviceFromRequest() {
        $devId = filter_input(INPUT_GET, 'device', FILTER_SANITIZE_STRING) ?? filter_input(INPUT_POST, 'device', FILTER_SANITIZE_STRING);
        if ($devId === NULL || $devId === FALSE) {
            $this->loggerInstance->debug(2, "Invalid device id provided\n");
            return(FALSE);
        }
        $Dev = \devices\Devices::listDevices();
        if (!isset($Dev['$devId'])) {
            $this->loggerInstance->debug(2, "Unrecognised system: $devId\n");
            return(FALSE);
        }
        return($devId);
    }

    /**
     * finds all the user certificates that originated in a given token
     * @param string $token
     * @return array|false returns FALSE if a token is invalid, otherwise array of certs
     */
    public function getUserCerts($token) {
        $validator = new \web\lib\common\InputValidation();
        $cleanToken = $validator->token($token);
        if ($cleanToken) {
            // check status of this silverbullet token according to info in DB:
            // it can be VALID (exists and not redeemed, EXPIRED, REDEEMED or INVALID (non existent)
            $tokenStatus = \core\ProfileSilverbullet::tokenStatus($cleanToken);
        } else {
            return false;
        }
        $profile = new \core\ProfileSilverbullet($tokenStatus['profile'], NULL);
        $userdata = $profile->userStatus($tokenStatus['db_id']);
        $allcerts = [];
        foreach ($userdata as $index => $content) {
            $allcerts = array_merge($allcerts, $content['cert_status']);
        }
        return $allcerts;
    }


    public $device;
    private $installerPath;

    /**
     * helper function to sort profiles by their name
     * @param \core\AbstractProfile $profile1 the first profile's information
     * @param \core\AbstractProfile $profile2 the second profile's information
     * @return int
     */
    private static function profileSort($profile1, $profile2) {
        return strcasecmp($profile1->name, $profile2->name);
    }

}
