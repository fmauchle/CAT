<?php

/*
 * *****************************************************************************
 * Contributions to this work were made on behalf of the GÉANT project, a 
 * project that has received funding from the European Union’s Framework 
 * Programme 7 under Grant Agreements No. 238875 (GN3) and No. 605243 (GN3plus),
 * Horizon 2020 research and innovation programme under Grant Agreements No. 
 * 691567 (GN4-1) and No. 731122 (GN4-2).
 * On behalf of the aforementioned projects, GEANT Association is the sole owner
 * of the copyright in all material which was developed by a member of the GÉANT
 * project. GÉANT Vereniging (Association) is registered with the Chamber of 
 * Commerce in Amsterdam with registration number 40535155 and operates in the 
 * UK as a branch of GÉANT Vereniging.
 * 
 * Registered office: Hoekenrode 3, 1102BR Amsterdam, The Netherlands. 
 * UK branch address: City House, 126-130 Hills Road, Cambridge CB2 1PQ, UK
 *
 * License: see the web/copyright.inc.php file in the file structure or
 *          <base_url>/copyright.php after deploying the software
 */

/**
 * This file contains the AbstractProfile class. It contains common methods for
 * both RADIUS/EAP profiles and SilverBullet profiles
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 *
 * @package Developer
 *
 */

namespace core;

use \Exception;

/**
 * This class represents an EAP Profile.
 * Profiles can inherit attributes from their IdP, if the IdP has some. Otherwise,
 * one can set attribute in the Profile directly. If there is a conflict between
 * IdP-wide and Profile-wide attributes, the more specific ones (i.e. Profile) win.
 * 
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 *
 * @license see LICENSE file in root directory
 *
 * @package Developer
 */
class DeploymentManaged extends AbstractDeployment {

    /**
     * This is the limit for dual-stack hosts. Single stack uses half of the FDs
     * in FreeRADIUS and take twice as many. initialise() takes this into
     * account.
     */
    const MAX_CLIENTS_PER_SERVER = 200;
    const PRODUCTNAME = "Managed SP";

    /**
     * the primary RADIUS server port for this SP instance
     * 
     * @var int
     */
    public $port1;

    /**
     * the backup RADIUS server port for this SP instance
     * 
     * @var int
     */
    public $port2;

    /**
     * the shared secret for this SP instance
     * 
     * @var string
     */
    public $secret;

    /**
     * the IPv4 address of the primary RADIUS server for this SP instance 
     * (can be NULL)
     * 
     * @var string
     */
    public $host1_v4;

    /**
     * the IPv6 address of the primary RADIUS server for this SP instance 
     * (can be NULL)
     * 
     * @var string
     */
    public $host1_v6;

    /**
     * the IPv4 address of the backup RADIUS server for this SP instance 
     * (can be NULL)
     * 
     * @var string
     */
    public $host2_v4;

    /**
     * the IPv6 address of the backup RADIUS server for this SP instance 
     * (can be NULL)
     * 
     * @var string
     */
    public $host2_v6;

    /**
     * the primary RADIUS server instance for this SP instance
     * 
     * @var string
     */
    public $radius_instance_1;

    /**
     * the backup RADIUS server instance for this SP instance
     * 
     * @var string
     */
    public $radius_instance_2;

    /**
     * Class constructor for existing deployments (use 
     * IdP::newDeployment() to actually create one). Retrieves all 
     * attributes from the DB and stores them in the priv_ arrays.
     * 
     * @param IdP        $idpObject       optionally, the institution to which this Profile belongs. Saves the construction of the IdP instance. If omitted, an extra query and instantiation is executed to find out.
     * @param string|int $deploymentIdRaw identifier of the deployment in the DB
     */
    public function __construct($idpObject, $deploymentIdRaw) {
        parent::__construct($idpObject, $deploymentIdRaw); // we now have access to our INST database handle and logging
        $this->entityOptionTable = "deployment_option";
        $this->entityIdColumn = "deployment_id";
        $this->type = AbstractDeployment::DEPLOYMENTTYPE_MANAGED;
        if (!is_numeric($deploymentIdRaw)) {
            throw new Exception("Managed SP instances have to have a numeric identifier");
        }
        $propertyQuery = "SELECT status,port_instance_1,port_instance_2,secret,radius_instance_1,radius_instance_2 FROM deployment WHERE deployment_id = ?";
        $queryExec = $this->databaseHandle->exec($propertyQuery, "i", $deploymentIdRaw);
        if (mysqli_num_rows(/** @scrutinizer ignore-type */ $queryExec) == 0) {
            throw new Exception("Attempt to construct an unknown DeploymentManaged!");
        }
        $this->identifier = $deploymentIdRaw;
        while ($iterator = mysqli_fetch_object(/** @scrutinizer ignore-type */ $queryExec)) {
            if ($iterator->secret == NULL && $iterator->radius_instance_1 == NULL) {
                // we are instantiated for the first time, initialise us
                $details = $this->initialise();
                $this->port1 = $details["port_instance_1"];
                $this->port2 = $details["port_instance_2"];
                $this->secret = $details["secret"];
                $this->radius_instance_1 = $details["radius_instance_1"];
                $this->radius_instance_2 = $details["radius_instance_2"];
                $this->status = AbstractDeployment::INACTIVE;
            } else {
                $this->port1 = $iterator->port_instance_1;
                $this->port2 = $iterator->port_instance_2;
                $this->secret = $iterator->secret;
                $this->radius_instance_1 = $iterator->radius_instance_1;
                $this->radius_instance_2 = $iterator->radius_instance_2;
                $this->status = $iterator->status;
            }
        }
        $server1details = $this->databaseHandle->exec("SELECT radius_ip4, radius_ip6 FROM managed_sp_servers WHERE server_id = '$this->radius_instance_1'");
        while ($iterator2 = mysqli_fetch_object(/** @scrutinizer ignore-type */ $server1details)) {
            $this->host1_v4 = $iterator2->radius_ip4;
            $this->host1_v6 = $iterator2->radius_ip6;
        }
        $server2details = $this->databaseHandle->exec("SELECT radius_ip4, radius_ip6 FROM managed_sp_servers WHERE server_id = '$this->radius_instance_2'");
        while ($iterator3 = mysqli_fetch_object(/** @scrutinizer ignore-type */ $server2details)) {
            $this->host2_v4 = $iterator3->radius_ip4;
            $this->host2_v6 = $iterator3->radius_ip6;
        }
        $this->attributes = $this->retrieveOptionsFromDatabase("SELECT DISTINCT option_name, option_lang, option_value, row 
                                            FROM $this->entityOptionTable
                                            WHERE $this->entityIdColumn = ?  
                                            ORDER BY option_name", "Profile");
    }

    /**
     * finds a suitable server which is geographically close to the admin
     * 
     * @param array  $adminLocation      the current geographic position of the admin
     * @param string $federation         the federation this deployment belongs to
     * @param array  $blacklistedServers list of server to IGNORE
     * @return string the server ID
     * @throws Exception
     */
    private function findGoodServerLocation($adminLocation, $federation, $blacklistedServers) {
        // find a server near him (list of all servers with capacity, ordered by distance)
        // first, if there is a pool of servers specifically for this federation, prefer it
        $servers = $this->databaseHandle->exec("SELECT server_id, radius_ip4, radius_ip6, location_lon, location_lat FROM managed_sp_servers WHERE pool = '$federation'");
        
        $serverCandidates = [];
        while ($iterator = mysqli_fetch_object(/** @scrutinizer ignore-type */ $servers)) {
            $maxSupportedClients = DeploymentManaged::MAX_CLIENTS_PER_SERVER;
            if ($iterator->radius_ip4 == NULL || $iterator->radius_ip6 == NULL) {
                // half the amount of IP stacks means half the amount of FDs in use, so we can take twice as many
                $maxSupportedClients = $maxSupportedClients * 2;
            }
            $clientCount1 = $this->databaseHandle->exec("SELECT port_instance_1 AS tenants1 FROM deployment WHERE radius_instance_1 = '$iterator->server_id'");
            $clientCount2 = $this->databaseHandle->exec("SELECT port_instance_2 AS tenants2 FROM deployment WHERE radius_instance_2 = '$iterator->server_id'");

            $clients = $clientCount1->num_rows + $clientCount2->num_rows;
            if (in_array($iterator->server_id, $blacklistedServers)) {
                continue;
            }
            if ($clients < $maxSupportedClients) {
                $serverCandidates[IdPlist::geoDistance($adminLocation, ['lat' => $iterator->location_lat, 'lon' => $iterator->location_lon])] = $iterator->server_id;
            }
            if ($clients > $maxSupportedClients * 0.9) {
                $this->loggerInstance->debug(1, "A RADIUS server for Managed SP (" . $iterator->server_id . ") is serving at more than 90% capacity!");
            }
        }
        if (count($serverCandidates) == 0 && $federation != "DEFAULT") {
            // we look in the default pool instead
            // recursivity! Isn't that cool!
            return $this->findGoodServerLocation($adminLocation, "DEFAULT", $blacklistedServers);
        }
        if (count($serverCandidates) == 0) {
            throw new Exception("No available server found for new SP! $federation ".print_r($serverCandidates, true));
        }
        // put the nearest server on top of the list
        ksort($serverCandidates);
        $this->loggerInstance->debug(1, $serverCandidates);
        return array_shift($serverCandidates);
    }

    /**
     * initialises a new SP
     * 
     * @return array details of the SP as generated during initialisation
     * @throws Exception
     */
    private function initialise() {
        // find out where the admin is located approximately
        $ourLocation = ['lon' => 0, 'lat' => 0];
        $geoip = DeviceLocation::locateDevice();
        if ($geoip['status'] == 'ok') {
            $ourLocation = ['lon' => $geoip['geo']['lon'], 'lat' => $geoip['geo']['lat']];
        }
        $inst = new IdP($this->institution);
        $ourserver = $this->findGoodServerLocation($ourLocation, $inst->federation , []);
        // now, find an unused port in the preferred server
        $foundFreePort1 = 0;
        while ($foundFreePort1 == 0) {
            $portCandidate = random_int(1050, 65535);
            $check = $this->databaseHandle->exec("SELECT port_instance_1 FROM deployment WHERE radius_instance_1 = '" . $ourserver . "' AND port_instance_1 = $portCandidate");
            if (mysqli_num_rows(/** @scrutinizer ignore-type */ $check) == 0) {
                $foundFreePort1 = $portCandidate;
            }
        }
        $ourSecondServer = $this->findGoodServerLocation($ourLocation, $inst->federation , [$ourserver]);
        $foundFreePort2 = 0;
        while ($foundFreePort2 == 0) {
            $portCandidate = random_int(1050, 65535);
            $check = $this->databaseHandle->exec("SELECT port_instance_2 FROM deployment WHERE radius_instance_2 = '" . $ourSecondServer . "' AND port_instance_2 = $portCandidate");
            if (mysqli_num_rows(/** @scrutinizer ignore-type */ $check) == 0) {
                $foundFreePort2 = $portCandidate;
            }
        }
        // and make up a shared secret that is halfways readable
        $futureSecret = $this->randomString(16, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
        $this->databaseHandle->exec("UPDATE deployment SET radius_instance_1 = '" . $ourserver . "', radius_instance_2 = '" . $ourSecondServer . "', port_instance_1 = $foundFreePort1, port_instance_2 = $foundFreePort2, secret = '$futureSecret' WHERE deployment_id = $this->identifier");
        return ["port_instance_1" => $foundFreePort1, "port_instance_2" => $foundFreePort2, "secret" => $futureSecret, "radius_instance_1" => $ourserver, "radius_instance_2" => $ourserver];
    }

    /**
     * update the last_changed timestamp for this deployment
     * 
     * @return void
     */
    public function updateFreshness() {
        $this->databaseHandle->exec("UPDATE deployment SET last_change = CURRENT_TIMESTAMP WHERE deployment_id = $this->identifier");
    }

    /**
     * gets the last-modified timestamp (useful for caching "dirty" check)
     * 
     * @return string the date in string form, as returned by SQL
     */
    public function getFreshness() {
        $execLastChange = $this->databaseHandle->exec("SELECT last_change FROM deployment WHERE deployment_id = $this->identifier");
        // SELECT always returns a resource, never a boolean
        if ($freshnessQuery = mysqli_fetch_object(/** @scrutinizer ignore-type */ $execLastChange)) {
            return $freshnessQuery->last_change;
        }
    }

    /**
     * Deletes the deployment from database
     * 
     * @return void
     */
    public function destroy() {
        $this->databaseHandle->exec("DELETE FROM deployment_option WHERE deployment_id = $this->identifier");
        $this->databaseHandle->exec("DELETE FROM deployment WHERE deployment_id = $this->identifier");
    }

    /**
     * deactivates the deployment.
     * TODO: needs to call the RADIUS server reconfiguration routines...
     * 
     * @return void
     */
    public function deactivate() {
        $this->databaseHandle->exec("UPDATE deployment SET status = " . DeploymentManaged::INACTIVE . " WHERE deployment_id = $this->identifier");
    }

    /**
     * activates the deployment.
     * TODO: needs to call the RADIUS server reconfiguration routines...
     * 
     * @return void
     */
    public function activate() {
        $this->databaseHandle->exec("UPDATE deployment SET status = " . DeploymentManaged::ACTIVE . " WHERE deployment_id = $this->identifier");
    }

}
