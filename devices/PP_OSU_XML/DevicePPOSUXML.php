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
 * This file contains the TestModule class
 *
 * This is a very basic example of using the CAT API.  
 *
 * The module contains two files
 * in the Files directory. They will illustrate the use of the {@link DeviceConfig::copyFile()} method.
 * One fille will be coppied without the name change, for the second we will provide a new name.
 * The API also contains a similar {@link DeviceConfig::translateFile()} method, which is special to Windows installers and not used in this example.
 *
 * This module will collect all certificate files stored in the database for a given profile and will copy them to the working directory.
 *
 * If, for the given profile, an information file is available, this will also be copied to the working directory.
 *
 * The installer will collect all available configuration attributes and save them to a file in the form of the PHP print_r output.
 *
 * Finally, the installer will create a zip archive containing all above files and this file 
 * will be sent to the user as the configurator file.
 *
 * Go to the {@link DeviceTestModule} and {@link DeviceConfig} class definitions to learn more.
 *  
 * @package ModuleWriting
 */

namespace devices\PP_OSU_XML;

use Exception;

/**
 * This is the main implementation class of the module
 *
 * The name of the class must the the 'Device' followed by the name of the module file
 * (without the '.php' extension), so in this case the file is "TestModule.php" and
 * the class is DeviceTestModule.
 *
 * The class MUST define the constructor method and one additional 
 * public method: {@link writeInstaller()}.
 *
 * All other methods and properties should be private. This example sets zipInstaller method to protected, so that it can be seen in the documentation.
 *
 * It is important to understand how the device module fits into the whole picture, so here is s short descrption.
 * An external caller (for instance {@link GUI::generateInstaller()}) creates the module device instance and prepares
 * its environment for a given user profile by calling {@link DeviceConfig::setup()} method.
 *      this will:
 *       - create the temporary directory and save its path as $this->FPATH
 *       - process the CA certificates and store results in $this->attributes['internal:CAs'][0]
 *            $this->attributes['internal:CAs'][0] is an array of processed CA certificates
 *            a processed certifincate is an array 
 *               'pem' points to pem feromat certificate
 *               'der' points to der format certificate
 *               'md5' points to md5 fingerprint
 *               'sha1' points to sha1 fingerprint
 *               'name' points to the certificate subject
 *               'root' can be 1 for self-signed certificate or 0 otherwise
 *       - save the info_file (if exists) and put the name in $this->attributes['internal:info_file_name'][0]
 * Finally, the module {@link DeviceConfig::writeInstaller ()} is called and the returned path name is used for user download.
 *
 * @package ModuleWriting
 */
class DevicePPOSUXML extends \core\DeviceConfig {

    /**
     * Constructs a Device object.
     *
     * @final not to be redefined
     */
    final public function __construct() {
        parent::__construct();
        $this->setSupportedEapMethods([\core\common\EAP::EAPTYPE_SILVERBULLET]);
    }

    /**
     * creates a AAAServerTrustRoot XML fragment. Currently unused, not clear
     * if Android supports this.
     * 
     * @return string
     */
    private function aaaServerTrustRoot() {

        $retval = '<Node>
        <NodeName>AAAServerTrustRoot</NodeName>';
        foreach ($this->attributes['internal:CAs'][0] as $oneCert) {
            $retval .= '<Node>
                         <NodeName>' . $oneCert['uuid'] . '</NodeName>
                             <Node>
                               <NodeName>CertSHA256Fingerprint</NodeName>
                               <Value>' . $oneCert['sha256'] . '</Value>
                             </Node>
                       </Node>
                  ';
        }
        $retval .= '</Node>';
        return $retval;
    }

    /**
     * creates a CreationDate XML fragment for use in Credential. Currently
     * unused, not clear if Android supports this.
     * 
     * @return string
     */
    private function credentialCreationDate() {
        $now = new \DateTime();
        return '<Node>
          <NodeName>CreationDate</NodeName>
          <Value>' . $now->format("Y-m-d") . "T" . $now->format("H:i:s") . "Z" . '</Value>
        </Node>';
    }

    /**
     * creates a HomeSP XML fragment for consortium identification.
     * 
     * @return string
     */
    private function homeSP() {
        $retval = '<Node>
        <NodeName>HomeSP</NodeName>
        <Node>
          <NodeName>FriendlyName</NodeName>
          <Value>' . sprintf(_("%s via Passpoint"), \config\ConfAssistant::CONSORTIUM['display_name']) . '</Value>
        </Node>
        <Node>
          <NodeName>FQDN</NodeName>
          <Value>' . $this->attributes['eap:server_name'][0] /* what, only one FQDN allowed? */ . '</Value>
        </Node>
        <Node>
          <NodeName>RoamingConsortiumOI</NodeName>
          <Value>';
        $oiList = "";
        $numberOfOi = count(\config\ConfAssistant::CONSORTIUM['interworking-consortium-oi']);
        foreach (\config\ConfAssistant::CONSORTIUM['interworking-consortium-oi'] as $index => $oneOi) {
            // according to spec, must be lowercase ASCII without dashes
            // but sample I got was all uppercase, so let's try with that
            $oiList .= str_replace("-", "", trim(strtoupper($oneOi)));
            if ($index < $numberOfOi - 1) {
                // according to spec, comma-separated
                $oiList .= ",";
            }
        }
        $retval .= $oiList . '</Value>
        </Node>
      </Node>';
        return $retval;
    }

    /**
     * creates a Credential XML fragment for client identification
     * 
     * @return string
     */
    private function credential() {
        $retval = '<Node>
        <NodeName>Credential</NodeName>';
        /* the example file I got did not include CreationDate, so omit it
         * 
         * $content .= $this->credentialCreationDate();
         */
        $retval .= '
          <Node>
            <NodeName>DigitalCertificate</NodeName>
            <Node>
              <NodeName>Realm</NodeName>
              <Value>' . $this->attributes['internal:realm'][0] . '</Value>
            </Node>
            <Node>
              <NodeName>CertificateType</NodeName>
              <Value>x509v3</Value>
            </Node>
            <Node>
              <NodeName>CertSHA256Fingerprint</NodeName>
              <Value>' . strtoupper($this->clientCert["sha256"]) /* the actual cert has to go... where? */ . '</Value>
            </Node>
          </Node>
      </Node>';
        return $retval;
    }

    /**
     * creates the overall perProviderSubscription XML
     * 
     * @return string
     */
    private function perProviderSubscription() {
        $retval = '<MgmtTree xmlns="syncml:dmddf1.2">
  <VerDTD>1.2</VerDTD>
  <Node>
    <NodeName>PerProviderSubscription</NodeName>
    <RTProperties>
      <Type>
        <DDFName>urn:wfa:mo:hotspot2dot0-perprovidersubscription:1.0</DDFName>
      </Type>
    </RTProperties>
    <Node>
      <NodeName>CATPasspointSetting</NodeName>';
        /* it seems that Android does NOT want the AAAServerTrustRoot section
          and instead always validates against the MIME cert attached

          $content .= $this->aaaServerTrustRoot();
         */
        $retval .= $this->homeSP();
        $retval .= $this->credential();

        $retval .= '</Node>
  </Node>
</MgmtTree>';
        return $retval;
    }

    /**
     * creates a MIME part containing the base64-encoded PPS-MO
     * 
     * @return string
     */
    private function mimeChunkPpsMo() {
        return '--{boundary}
Content-Type: application/x-passpoint-profile
Content-Transfer-Encoding: base64

' . chunk_split(base64_encode($this->perProviderSubscription()), 76, "\n");
    }

    /**
     * creates a MIME part containing the base64-encoded CA certs (PEM)
     * 
     * @return string
     */
    private function mimeChunkCaCerts() {
        $retval = '--{boundary}
Content-Type: application/x-x509-ca-cert
Content-Transfer-Encoding: base64
';
        // then, another PEM chunk for each CA certificate we referenced earlier
        // only leaves me to wonder what the "URL" for those is...
        // TODO: more than one CA is currently untested
        foreach ($this->attributes['internal:CAs'][0] as $oneCert) {
            $retval .= chunk_split(base64_encode($oneCert['pem']), 76, "\n");
        }
        return $retval;
    }

    /**
     * creates a MIME part containing the base64-encoded client cert PKCS#12
     * structure - no password.
     * 
     * @return string
     */
    private function mimeChunkClientCert() {
        return '--{boundary}
Content-Type: application/x-pkcs12
Content-Transfer-Encoding: base64

' . chunk_split(base64_encode($this->clientCert['certdataclear']), 76, "\n"); // is PKCS#12, with cleartext key
    }
    /**
     * prepare the PPS-MO file with cert MIME attachments
     *
     * @return string installer path name
     */
    public function writeInstaller() {
        $this->loggerInstance->debug(4, "HS20 PerProviderSubscription Managed Object Installer start\n");
        // sigh... we need to construct a MIME envelope for the payload and the cert data
        $content_encoded = 'Content-Type: multipart/mixed; boundary={boundary}
Content-Transfer-Encoding: base64

';
        $content_encoded .= $this->mimeChunkPpsMo();
        $content_encoded .= $this->mimeChunkCaCerts();
        $content_encoded .= $this->mimeChunkClientCert();
        // this was the last MIME chunk; end the file orderly
        $content_encoded .= "--{boundary}--\n";
        // strangely enough, now encode ALL OF THIS in base64 again. Whatever.
        file_put_contents('installer_profile', chunk_split(base64_encode($content_encoded), 76, "\n"));

        // $fileName = $this->installerBasename . '.bin';
        $fileName = "passpoint.config";

        if (!$this->sign) {
            rename("installer_profile", $fileName);
            return $fileName;
        }

        // still here? We are signing. That actually can't be - the spec doesn't
        // foresee signing.
        // but if they ever change their mind, we are prepared

        $outputFromSigning = system($this->sign . " installer_profile '$fileName' > /dev/null");
        if ($outputFromSigning === FALSE) {
            $this->loggerInstance->debug(2, "Signing the ONC installer $fileName FAILED!\n");
        }

        return $fileName;
    }

    /**
     * prepare module desctiption and usage information
     * 
     * @return string HTML text to be displayed in the information window
     */
    public function writeDeviceInfo() {
        $out = "<p>";
        $out .= _("This installer is an example only. It produces a zip file containig the IdP certificates, info and logo files (if such have been defined by the IdP administrator) and a dump of all available attributes.");
        return $out;
    }

}
