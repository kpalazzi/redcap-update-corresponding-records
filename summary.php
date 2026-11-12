<?php
/**
 * REDCap External Module: Update Corresponding Records
 * Summary page: this project's update instructions, with Export, and Import, Edit settings
 * and Run now for users with Project Design rights (built by UpdateCorrespondingRecords::summaryPage()).
 * Example URL:
 * /ExternalModules/?prefix=update_corresponding_records&page=summary&pid=45
 * @author Kerrin Palazzi, Hunter Medical Research Institute
 */
namespace HMRI\UpdateCorrespondingRecords;

if (!isset($module) || !($module instanceof UpdateCorrespondingRecords)) { exit(); }

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->summaryPage();
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
