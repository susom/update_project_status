<?php

 namespace Stanford\UpdateProjectStatus;
/** @var \Stanford\UpdateProjectStatus\UpdateProjectStatus $module*/


try{
    $index = filter_var($_GET['index'], FILTER_SANITIZE_NUMBER_INT);
    $module->emDebug("Rule index: $index");
    $module->executeUpdateRules($index);
}catch (\Exception $e){
    $module->emError($e->getMessage());
    \REDCap::logEvent('Exception', $e->getMessage(), LOG_ERR);
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
}