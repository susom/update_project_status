<?php

namespace Stanford\UpdateProjectStatus;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise;

include_once "emLoggerTrait.php";

class UpdateProjectStatus extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    private $notificationAPIEM = null;

    private $client = null;

    private $rules = [];

    private $notificationPID = null;

    private $status = array(
        '0' => 'Development',
        '1' => 'Production',
        '2' => 'Analysis/Cleanup',
        '99' => 'Completed'
    );
    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function executeUpdateRules($index)
    {
        foreach ($this->getRules() as $i => $rule) {
            // match requested rule with current one in the loop.
            if ($i == $index) {

                $sourceStatus = $rule['source-status'];
                $destinationStatus = $rule['destination-status'];
                // get record to make sure the record is correct.
                $record = $this->getNotificationRecord($rule['notification-record-id']);
                if (!empty($record)) {
                    $pids = $this->getHistoryLogPIDs($rule['notification-record-id'], $rule['days-elapsed']);
                    foreach ($pids as $pid) {
                        $sql = "SELECT * from redcap_projects where project_id = $pid";
                        $q = db_query($sql);
                        $row = db_fetch_assoc($q);
                        // if project already in desired status then skip.
                        if ($row['status'] == $destinationStatus) {
                            continue;
                        }

                        // if current project status is same as source destination then update it.
                        if ($row['status'] == $sourceStatus) {
                            $updateSQL = "UPDATE redcap_projects set status = $destinationStatus ";
                            if ($destinationStatus == 2) {
                                $updateSQL .= ", inactive_time = '" . NOW . "'";
                            }

                            // if desired status is complete we need to add the complete time and user.
                            if ($destinationStatus == 99) {
                                // is same status as analysis but add complete_time
                                $updateSQL = "UPDATE redcap_projects set status = 2 ";
                                $updateSQL .= ",completed_time = '" . NOW . "', completed_by = '" . db_escape(defined('USERID')?USERID:'SYSTEM') . "'";
                                \Logging::logEvent("", "redcap_projects", "MANAGE", $this->getNotificationPID(), "project_id = " . $this->getNotificationPID(), "Project marked as Completed");

                            }
                            $updateSQL .= "WHERE project_id = $pid";
                            $updateQ = db_query($updateSQL);
                            \REDCap::logEvent("PID $pid status changed from ". $this->status[$sourceStatus] ." to ". $this->status[$destinationStatus], "",  "",  $rule['notification-record-id'],  null, $this->getNotificationPID());
                        }

                    }
                }

            }
        }
    }

    /**
     * Function to get the date before elapsed days
     * @param $days
     * @return string
     * @throws \DateMalformedStringException
     */
    public function getElapsedDate($days)
    {
        $timestamp = time(); // or use any specific timestamp

        // Create a DateTime object from the timestamp
        $date = new \DateTime("@$timestamp");

        // Subtract 30 days
        $date->modify("-$days days");
        $newTimestamp = $date->getTimestamp();

        // Format the modified timestamp as 'yyyy-mm-dd HH:mm:ss'
        $formattedDate = date('Y-m-d H:i:s', $newTimestamp);

        return $formattedDate;
    }

    private function getHistoryLogPIDs($recordId, $daysElapsed = 30)
    {
        $date = $this->getElapsedDate($daysElapsed);
        $eventId = $this->getFirstEventId();
        $sql = "SELECT REGEXP_REPLACE(data_values, '[note_project_id=\'\s\t]', '' ) as pids
                FROM " . \Logging::getLogEventTable($this->getNotificationPID()) . " WHERE project_id = " . $this->getNotificationPID() . " and pk = '$recordId'
				and timestamp(ts) < '$date'
				and (
				(
					(event_id = $eventId or event_id is null)
					and legacy = 0 and data_values not like '[instance = %'
					and
					(
						(
							event in ('INSERT', 'UPDATE')
							and description in ('Create record', 'Update record', 'Update record (import)',
								'Create record (import)', 'Merge records', 'Update record (API)', 'Create record (API)',
								'Update record (DTS)', 'Update record (DDP)', 'Erase survey responses and start survey over',
								'Update survey response', 'Create survey response', 'Update record (Auto calculation)',
								'Update survey response (Auto calculation)', 'Delete all record data for single form',
								'Delete all record data for single event', 'Update record (API) (Auto calculation)')
							and (data_values like '%\nnote\_project\_id = %' or data_values like 'note\_project\_id = %' 
								or data_values like '%\nnote\_project\_id(%) = %' or data_values like 'note\_project\_id(%) = %')
						)
						or
						(event = 'DOC_DELETE' and data_values = 'note_project_id')
						or
						(event = 'DOC_UPLOAD' and (data_values like '%\nnote\_project\_id = %' or data_values like 'note\_project\_id = %' 
													or data_values like '%\nnote\_project\_id(%) = %' or data_values like 'note\_project\_id(%) = %'))
					)
				)
				or 
				(event = 'DELETE' and description like 'Delete record%' and (event_id is null or event_id in ('$eventId')))
				)
				order by log_event_id DESC LIMIT 1";
        $q = db_query($sql);
        $results = db_fetch_assoc($q);
        $pids = trim($results['pids']);
        return explode(",", $pids);

    }

    public function getNotificationRecord($id)
    {
        $params = array(
            "records" => [$id],
            "return_format" => "json",
            "project_id" => $this->getNotificationPID()
        );
        $response = \REDCap::getData($params);
        return json_decode($response, true);;
    }

    public function CheckProjectStatus()
    {
        // Using notification API em get the Notification PID.
        try {
            $pid = $this->getNotificationPID();

            $url = $this->getUrl('ajax/cron', true, true) . '&NOAUTH&pid=' . $pid;
            $this->emDebug("Start cron.");
            $client = $this->getClient();
            foreach ($this->getRules() as $index => $rule) {
                $responses[$index] = $client->get($url . '&index=' . $index);

            }
            $this->emDebug("Process Rules");

            $this->emDebug($responses);

        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            \REDCap::logEvent($responseBodyAsString, "", "", null, null, $pid);
            $this->emError($e->getMessage());
        } catch (\Exception $e) {
            \REDCap::logEvent($e->getMessage(), "", "", null, null, $pid);
            $this->emError($e->getMessage());
        }
    }

    /**
     * @return \Stanford\RedcapNotificationsAPI\RedcapNotificationsAPI
     */
    public function getNotificationAPIEM(): \Stanford\RedcapNotificationsAPI\RedcapNotificationsAPI
    {
        if (!$this->notificationAPIEM) {
            if ($this->getSystemSetting('notification-api-em')) {
                $this->setNotificationAPIEM(\ExternalModules\ExternalModules::getModuleInstance($this->getSystemSetting('notification-api-em')));
            } else {
                throw new \Exception("System settings not set");
            }
        }
        return $this->notificationAPIEM;
    }

    /**
     * @param \Stanford\RedcapNotificationsAPI\RedcapNotificationsAPI $notificationAPIEM
     */
    public function setNotificationAPIEM(\Stanford\RedcapNotificationsAPI\RedcapNotificationsAPI $notificationAPIEM): void
    {
        $this->notificationAPIEM = $notificationAPIEM;
    }


    /**
     * @return \GuzzleHttp\Client
     */
    public function getClient(): \GuzzleHttp\Client
    {
        if (!$this->client) {
            $this->setClient(new \GuzzleHttp\Client());
        }
        return $this->client;
    }

    /**
     * @param \GuzzleHttp\Client $client
     */
    public function setClient(\GuzzleHttp\Client $client): void
    {
        $this->client = $client;
    }

    public function getRules()
    {
        if (!$this->rules) {
            $this->setRules();
        }
        return $this->rules;
    }

    /**
     * save $instances
     */
    public function setRules(): void
    {
        $this->rules = $this->getSubSettings('rules', $this->getNotificationPID());;
    }

    /**
     * @return null
     */
    public function getNotificationPID(): string
    {
        if (!$this->notificationPID) {
            $this->setNotificationPID($this->getNotificationAPIEM()->getSystemSetting('notification-pid'));
        }
        return $this->notificationPID;
    }

    /**
     * @param null $notificationPID
     */
    public function setNotificationPID($notificationPID): void
    {
        $this->notificationPID = $notificationPID;
    }


}
