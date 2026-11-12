<?php
/**
 * REDCap External Module: Update Corresponding Records
 * Push field values to every matching record in another project, or pull them in from a
 * matching record in another project, when a form is saved.
 * @author Kerrin Palazzi, Hunter Medical Research Institute
 */
namespace HMRI\UpdateCorrespondingRecords;

use ExternalModules\AbstractExternalModule;

require_once __DIR__.'/UpdateInstruction.php';

class UpdateCorrespondingRecords extends AbstractExternalModule {

    const EM_NAME = "Update Corresponding Records";

    /**
     * Element types that can't be used as a key (their values aren't a single plain value).
     * Multiple-answer checkboxes can still be copied, as a whole set (see fieldIssues()).
     */
    const UNSUPPORTED_TYPES = array('checkbox', 'file', 'descriptive');

    /** How those types are named in messages ("checkbox" alone could be read as a radio button). */
    const TYPE_LABELS = array('checkbox' => 'multiple-answer checkbox', 'file' => 'file upload', 'descriptive' => 'descriptive');

    /** "Not copied" reason for an instrument field whose match in the other project repeats. */
    const REPEATING_REASON = 'on a repeating instrument or event';

    private $projectCache = array();      // pid => \Project|null
    private $instructionCache = array();  // pid => UpdateInstruction[]

    // =====================================================================
    // Hooks
    // =====================================================================

    /**
     * redcap_save_record
     * Runs after every data entry form AND survey page save, for the instructions set up
     * in this project whose trigger form was saved. On a survey nobody is logged in
     * (USERID is "[survey respondent]"), so nothing on this path may call getUser() or
     * check user rights - rights are only checked when instructions are saved or imported.
     * Data imports and the API don't fire this hook, and changes this module writes with
     * saveData() don't fire it either, so instructions never chain.
     */
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id = null, $survey_hash = null, $response_id = null, $repeat_instance = 1) {
        $project_id = (int) $project_id;
        $record = (string) $record;

        foreach ($this->getInstructions($project_id) as $instruction) {
            if (!$instruction->config_enabled || !$instruction->triggersOnForm((string) $instrument)) continue;
            $issues = $this->instructionIssues($instruction);
            if (!empty($issues)) {
                $this->logRun('skipped', $instruction, $record, array('reason' => implode('; ', $issues)));
                continue;
            }
            $this->runInstruction($instruction, $record, (int) $event_id, (string) $instrument, max(1, (int) $repeat_instance));
        }
    }

    /**
     * Run one instruction for one saved record/event/instance, as after a save of its
     * trigger form - used by the save hook and by "Run now". The instruction's issues must
     * already have been checked (instructionIssues() also works out its fields).
     * @param bool $quiet "Run now": don't log a push that changed nothing
     * @return array counts: updated (records), failed (0/1), skipped (records skipped by the
     *               condition in the other project)
     */
    protected function runInstruction(UpdateInstruction $ins, string $record, int $eventId, string $instrument, int $instance, bool $quiet = false): array {
        $none = array('updated' => 0, 'failed' => 0, 'skipped' => 0);
        try {
            // On a repeating form or event, check the condition against the instance just
            // saved; without it REDCap evaluates repeating fields as blank.
            $repeatKey = $this->repeatKey($this->getProj($ins->project_id), $eventId, $instrument);
            if ($ins->trigger_logic !== '' && \REDCap::evaluateLogic($ins->trigger_logic, $ins->project_id, $record, $eventId,
                    $repeatKey === null ? 1 : $instance, (string) $repeatKey, $instrument) !== true) {
                return $none; // trigger condition not met - the rule simply doesn't apply, nothing to log
            }
            return $ins->isPull()
                ? $this->runPull($ins, $record, $eventId, $instrument, $instance)
                : $this->runPush($ins, $record, $eventId, $instrument, $instance, $quiet);
        } catch (\Throwable $e) {
            $this->failRun($ins, $record, 'unexpected error: '.$e->getMessage());
            return array('updated' => 0, 'failed' => 1, 'skipped' => 0);
        }
    }

    /**
     * redcap_module_ajax
     * Requests from the summary page's buttons, sent with the framework's JavaScript Module
     * Object. The framework only lets through logged-in users, the actions declared in
     * config.json (auth-ajax-actions), REDCap's CSRF token and the right project; each action
     * then checks rights itself (export is open to anyone who can open the summary page).
     */
    function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        $payload = is_array($payload) ? $payload : array();
        switch ($action) {
            case 'export-instructions':
                return array('csv' => $this->export(), 'filename' => 'update_corresponding_records_instructions_pid'.(int) $project_id.'.csv');
            case 'import-instructions':
                $errors = $this->import((string) ($payload['csv'] ?? ''));
                return array('result' => empty($errors) ? 1 : 0, 'errors' => array_values($errors));
            case 'run-now':
                return $this->runNowRequest($payload);
        }
        return array('error' => 'Unknown action.');
    }

    /**
     * redcap_every_page_top
     * On the project's External Modules page: add a "View summary of update instructions"
     * link under this module's description (the technique Copy Data on Save uses),
     * auto-open the Configure dialog when arriving from the summary page's Edit settings,
     * and pre-select Push for any instruction with no direction yet (the dialog can't show
     * config.json defaults, and a never-saved direction is treated as push).
     */
    function redcap_every_page_top($project_id) {
        if (!defined('PAGE') || empty($project_id) || PAGE !== 'manager/project.php') return;
        $this->repairEmptyGroups((int) $project_id);

        $summaryPageUrl = $this->getUrl('summary.php');
        $selector = 'tr[data-module="'.$this->PREFIX.'"]';
        // Other projects whose instructions this user can see but not change: pid => title.
        $lockedProjects = array();
        try {
            foreach ($this->lockedInstructions((int) $project_id) as $ins) {
                $lockedProjects[(string) $ins->other_project_id] = $this->projectTitle($ins->other_project_id);
            }
        } catch (\Throwable $e) {
            // Nothing is made read-only in the dialog; saving still checks rights.
        }
        ?>
        <script type="text/javascript">
            $(function(){
                var prefix = <?= json_encode($this->PREFIX) ?>;
                $(<?= json_encode($selector.' div.external-modules-description') ?>)
                    .append('<div class="mt-1"><a href="'+<?= json_encode($summaryPageUrl) ?>+'"><i class="fa-solid fa-list-ol" style="margin-right:5px;"></i>View summary of update instructions</a></div>');

                // The Configure dialog is built after the page loads and rebuilt when an
                // instruction is added, so these run whenever the dialog's contents change.
                var thisPid = <?= json_encode((string) (int) $project_id) ?>;
                var lockedProjects = <?= json_encode((object) $lockedProjects) ?>;
                var rowsOf = function($start){
                    var $end = ExternalModules.Settings.prototype.getEndOfSub($start);
                    return $start.add($start.nextUntil($end)).add($end);
                };
                var adjustDialog = function(){
                    var $modal = $('#external-modules-configure-modal');
                    if ($modal.data('module') !== prefix || !$modal.is(':visible')) return;

                    // Shade every row of an instruction orange (odd) or blue (even), so it's easy
                    // to see where one ends. The module does this itself: REDCap only shades
                    // repeating settings in newer versions (not 15.9.3 or 17.0.0), and there by
                    // the innermost instance number, so an instruction's "Leave out" and "Also
                    // copy" rows (e.g. 2.1) could differ from the instruction itself.
                    var shades = ['rgba(253, 126, 20, 0.15)', 'rgba(0, 123, 255, 0.15)'];
                    $modal.find('tr.sub_start[field="update-config"]').each(function(i){
                        var $start = $(this);
                        var $end = ExternalModules.Settings.prototype.getEndOfSub($start);
                        $start.add($start.nextUntil($end)).add($end).children('td').css('background-color', shades[i % 2]);
                    });

                    // Show an instruction's "only if empty" and "Leave out" rows only while it has
                    // an instrument to copy. (config.json branchingLogic can't do this: inside
                    // repeating settings it only reads the first instruction, and shows or hides
                    // every instruction's rows.)
                    $modal.find('select[name^="copy-instrument____"]').each(function(){
                        var index = this.name.split('____')[1];
                        var hasInstrument = !!$(this).val();
                        $modal.find('[name="instrument-only-if-empty____' + index + '"], [name^="leave-out____' + index + '____"], [name^="leave-out-field____' + index + '____"]')
                            .closest('tr').toggle(hasInstrument);
                    });

                    // Read-only instructions: ones whose other project this user has no Project
                    // Design rights in. They're shown (so nobody is surprised by instructions they
                    // can't see) with a note, and can't be edited or deleted; "+" still adds a new
                    // instruction after them. Disabled inputs are still sent when the dialog is
                    // saved, and validateSettings() checks they come back unchanged. Each
                    // instruction is checked once, as it first appears, so a new instruction
                    // is never locked.
                    $modal.find('tr.sub_start[field="update-config"]').each(function(){
                        var $start = $(this);
                        if (!$start.data('ucrLockChecked')) {
                            $start.data('ucrLockChecked', true);
                            var otherPid = rowsOf($start).find('select[name^="dest-project____"]').first().val() || '';
                            if (!Object.prototype.hasOwnProperty.call(lockedProjects, otherPid)) return;
                            $start.data('ucrLocked', true);
                            var pull = rowsOf($start).find('input[type=radio][name^="direction____"][value="pull"]').first().is(':checked');
                            var $note = $('<div class="ucr-locked-note alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size:12px;font-weight:normal;"></div>')
                                .append('<i class="fa-solid fa-lock me-1"></i><b>Read only.</b> ')
                                .append(document.createTextNode('This instruction copies ' + (pull ? 'from' : 'to') + ' "' + lockedProjects[otherPid] + '" (PID ' + otherPid
                                    + '), where you don\'t have Project Design rights. It still runs when its trigger form is saved. To change or delete it, ask someone with Project Design rights in both projects, or an administrator.'));
                            $start.children('td').last().prev().append($note);
                        }
                        if (!$start.data('ucrLocked')) return;
                        var $rows = rowsOf($start);
                        $rows.find('input, select, textarea').not('[type=hidden]').prop('disabled', true);
                        $rows.find('td.external-modules-input-td').not($start.children()).css('pointer-events', 'none'); // also blocks the searchable dropdowns
                        $rows.find('.external-modules-add-instance, .external-modules-remove-instance, .external-modules-add-instance-subsettings, .external-modules-remove-instance-subsettings')
                            .not($start.find('.external-modules-add-instance')).hide();
                    });
                    // A new instruction can't pick a project the user has no Design rights in
                    // (REDCap adds those to every "Other project" list, labelled as such).
                    $modal.find('select[name^="dest-project____"]').not(':disabled').find('option').each(function(){
                        if (Object.prototype.hasOwnProperty.call(lockedProjects, this.value) && !this.selected) $(this).remove();
                    });

                    // Deleting the only instruction: REDCap hides "−" when a repeating group has one
                    // instance (its own "−" would leave no rows, and no "+" to add one). Show it,
                    // and have it clear the instruction instead; a blank instruction is treated as
                    // deleted once saved. With several instructions, REDCap's "−" removes as usual.
                    var $removes = $modal.find('tr.sub_start[field="update-config"] .external-modules-remove-instance')
                        .filter(function(){ return !$(this).closest('tr').data('ucrLocked'); });
                    if ($removes.length === 1 && $modal.find('tr.sub_start[field="update-config"]').length === 1) $removes.show();
                    $removes.attr('title', 'Delete this instruction').each(function(){
                        if ($(this).data('ucrBound')) return;
                        $(this).data('ucrBound', true).on('click', function(e){
                            if ($modal.find('tr.sub_start[field="update-config"]').length > 1) return; // REDCap's own handler removes it
                            e.preventDefault();
                            e.stopImmediatePropagation(); // don't let REDCap remove the last instruction's rows
                            if (!confirm("Delete this instruction?\n\nIt's cleared now and deleted when you click Save. Cancel the dialog to keep it.")) return;
                            clearInstruction($(this).closest('tr'));
                            adjustDialog();
                        });
                    });

                    // Tick Enabled on a new, blank instruction (config.json defaults aren't applied
                    // to the dialog). Each checkbox is looked at once, so unticking it sticks;
                    // saved instructions keep whatever was saved.
                    $modal.find('input[type=checkbox][name^="config-enabled____"]').each(function(){
                        if ($(this).data('ucrDefaulted')) return;
                        $(this).data('ucrDefaulted', true);
                        var index = this.name.split('____')[1];
                        var blank = ['config-description', 'trigger-form', 'source-key-field', 'dest-project', 'dest-key-field', 'copy-instrument']
                            .every(function(key){ return !$modal.find('[name="' + key + '____' + index + '"]').val(); });
                        if (blank) $(this).prop('checked', true);
                    });

                    // Pre-select Push for any instruction with no direction yet.
                    var groups = {};
                    $modal.find('input[type=radio][name^="direction____"]').each(function(){
                        (groups[this.name] = groups[this.name] || []).push(this);
                    });
                    $.each(groups, function(name, radios){
                        if (!radios.some(function(r){ return r.checked; })) {
                            $(radios).filter('[value="push"]').prop('checked', true);
                        }
                    });

                    // When the other project is this project, show a dropdown of this project's
                    // fields instead of the "Field name in other project" text box. The text box
                    // stays (hidden) and is what gets saved; the dropdown has no name.
                    $modal.find('input[name^="dest-field____"]').each(function(){
                        var $input = $(this);
                        // Grey hint for the default: blank means the same variable name.
                        if (!$input.attr('placeholder')) $input.attr('placeholder', 'same name');
                        var parts = this.name.split('____');               // dest-field, instance, pair
                        var otherPid = $modal.find('select[name="dest-project____' + parts[1] + '"]').val();
                        var $picker = $input.next('select.ucr-same-project-field');
                        if (otherPid !== thisPid) {
                            $picker.remove();
                            $input.show();
                            return;
                        }
                        if (!$picker.length) {
                            $picker = $('<select class="ucr-same-project-field" style="max-width:100%;"></select>');
                            $picker.append($('<option value="">').text('(same name)'));
                            $modal.find('select[name="source-field____' + parts[1] + '____' + parts[2] + '"] option').each(function(){
                                if (this.value !== '') $picker.append($('<option>').val(this.value).text($(this).text()));
                            });
                            $picker.on('change', function(){ $input.val($(this).val()).trigger('change'); });
                            $input.after($picker).hide();
                        }
                        if ($picker.val() !== ($input.val() || '')) $picker.val($input.val() || '');
                    });
                };
                // Empty one instruction in the dialog: extra "Leave out" and "Also copy" rows are
                // removed with REDCap's own "−", and every value in what's left is cleared.
                var clearInstruction = function($startTr){
                    var endOf = function(){ return ExternalModules.Settings.prototype.getEndOfSub($startTr); };
                    ['leave-out', 'copy-fields'].forEach(function(key){
                        for (var guard = 0; guard < 200; guard++) {
                            var $starts = $startTr.nextUntil(endOf()).filter('tr.sub_start[field="' + key + '"]');
                            if ($starts.length <= 1) break;
                            $starts.last().find('.external-modules-remove-instance').first().trigger('click');
                        }
                    });
                    var $rows = $startTr.add($startTr.nextUntil(endOf())).add(endOf());
                    // Hidden inputs are REDCap's markers for each group instance ("true"): keep them.
                    $rows.find('textarea, input').not('[type=hidden], [type=checkbox], [type=radio], [type=button], [type=submit]').val('');
                    $rows.find('input[type=checkbox], input[type=radio]').prop('checked', false);
                    $rows.find('select').val('').trigger('change');
                };
                new MutationObserver(adjustDialog).observe(document.body, { childList: true, subtree: true });
                $(document).on('change', '#external-modules-configure-modal select[name^="dest-project____"], #external-modules-configure-modal select[name^="copy-instrument____"]', adjustDialog);
            });
        </script>
        <?php
        if (isset($_GET['ucr_config'])) {
            ?>
            <script type="text/javascript">
                $(window).on('load', function() {
                    history.replaceState({}, null, location.href.split("&ucr_config")[0]);
                    setTimeout(function() {
                        $(<?= json_encode($selector.' button.external-modules-configure-button') ?>).trigger('click');
                    }, 1000);
                });
            </script>
            <?php
        }
    }

    /**
     * Instructions imported by earlier builds saved an empty "Leave out" or "Also copy" group
     * as no rows, and REDCap's dialog then shows no row (or + button) to add one. Give each
     * empty group one blank row, as the dialog itself saves it. Runs on the External Modules
     * page for users who can change settings; values already saved are never altered.
     */
    protected function repairEmptyGroups(int $pid) {
        try {
            if (!$this->userHasDesignRights($pid)) return;
            $settings = $this->getProjectSettings($pid);
            $changed = false;
            // A never-saved Enabled counts as ticked, but the dialog shows it unticked, so the
            // next save would switch the instruction off. Save it as ticked (blank ones aside).
            $instances = array_keys((array) ($settings['update-config'] ?? array()));
            $configs = UpdateInstruction::configsFromRawSettings($settings);
            $enabled = (array) ($settings['config-enabled'] ?? array());
            foreach ($instances as $n => $i) {
                if (!array_key_exists($i, $enabled)) $enabled[$i] = null; // keep the list complete, so it's saved as a list
                if ($enabled[$i] === null && !(new UpdateInstruction($configs[$n], $n, $pid))->isBlank()) {
                    $enabled[$i] = true;
                    $changed = true;
                }
            }
            if ($changed) {
                ksort($enabled);
                $settings['config-enabled'] = $enabled;
            }
            foreach (array('copy-fields' => array('source-field', 'dest-field', 'only-if-empty'), 'leave-out' => array('leave-out-field')) as $group => $children) {
                foreach (array_keys((array) ($settings['update-config'] ?? array())) as $i) {
                    if (isset($settings[$group][$i]) && is_array($settings[$group][$i]) && count($settings[$group][$i]) === 0) {
                        $settings[$group][$i] = array('true');
                        foreach ($children as $child) $settings[$child][$i] = array(null);
                        $changed = true;
                    }
                }
            }
            if ($changed) $this->setProjectSettings($settings, $pid);
        } catch (\Throwable $e) {
            // Leave the settings as they are; the dialog still works for everything else.
        }
    }

    /**
     * redcap_module_configuration_settings
     * Display-only: give the Configure dialog's summary button its real URL.
     */
    function redcap_module_configuration_settings($project_id, $settings) {
        if (empty($project_id)) return $settings;
        foreach ($settings as &$setting) {
            if (($setting['key'] ?? null) === 'summary-page') {
                $setting['name'] = str_replace('href="#"', 'href="'.htmlspecialchars($this->getUrl('summary.php'), ENT_QUOTES).'"', $setting['name']);
            }
        }
        unset($setting);
        return $settings;
    }

    /**
     * validateSettings
     * Lock-down: every instruction saved in the Configure dialog must be saved by someone
     * with Project Design rights in this project AND its other project (admins pass). Any
     * failure blocks the whole save; REDCap shows the message in a browser alert.
     * Instructions whose other project the user has no Design rights in are read-only in the
     * dialog: they must come back unchanged (they may move up if one above is deleted), and
     * can't be deleted.
     */
    function validateSettings($settings) {
        $pid = $this->getProjectId();
        if (empty($pid)) return null; // Control Center save - no project instructions
        $pid = (int) $pid;

        // Saved instructions this user can't change, as [fingerprint, instruction].
        $locked = array();
        foreach (UpdateInstruction::configsFromRawSettings($this->getProjectSettings($pid)) as $i => $config) {
            $instruction = new UpdateInstruction($config, $i, $pid);
            if (!$instruction->isBlank() && $this->isLockedForUser($instruction)) {
                $locked[] = array(UpdateInstruction::fingerprint($config), $instruction);
            }
        }

        $messages = array();
        foreach (UpdateInstruction::configsFromRawSettings($settings) as $i => $config) {
            $instruction = new UpdateInstruction($config, $i, $pid);
            if ($instruction->other_project_id <= 0) continue; // no other project yet - nothing can be copied
            // An unchanged read-only instruction is kept as it is (each saved one matches once).
            $fingerprint = UpdateInstruction::fingerprint($config);
            foreach ($locked as $k => $saved) {
                if ($saved[0] === $fingerprint) {
                    unset($locked[$k]);
                    continue 2;
                }
            }
            foreach ($this->lockdownIssues($instruction) as $issue) {
                $messages[] = "Update Configuration {$instruction->sequence}: {$issue}";
            }
        }
        // Any read-only instruction not sent back unchanged was edited or deleted.
        foreach ($locked as $saved) {
            $instruction = $saved[1];
            $messages[] = "Update Configuration {$instruction->label()} can't be changed or deleted: you need Project Design rights in \""
                .$this->projectTitle($instruction->other_project_id)."\" (pid {$instruction->other_project_id})";
        }
        $messages = array_values(array_unique($messages));
        return empty($messages) ? null : "Update Corresponding Records: settings not saved.\n\n".implode("\n\n", $messages);
    }

    // =====================================================================
    // Push and pull
    // =====================================================================

    /**
     * Where the saved form's data lives: '' for a repeating event (REDCap's key for the
     * whole event), the form name for a repeating instrument, or null if it doesn't repeat.
     */
    protected function repeatKey($proj, int $eventId, string $instrument): ?string {
        if ($proj === null) return null;
        if ($proj->isRepeatingEvent($eventId)) return '';
        if ($proj->isRepeatingForm($eventId, $instrument)) return $instrument;
        return null;
    }

    /**
     * This project's side of a run: the saved record's key value and its values in the
     * saved event - for a repeating form or event, the instance just saved, overlaid on the
     * event's non-repeating values.
     * @return array [keyValue, row]
     */
    protected function savedSide(UpdateInstruction $ins, string $record, int $eventId, ?string $repeatKey, int $instance): array {
        $pid = $ins->project_id;
        $proj = $this->getProj($pid);
        $keyEventId = $ins->key_event !== '' ? $this->eventIdFor($proj, $ins->key_event) : $eventId;
        $data = \REDCap::getData(array(
            'project_id'    => $pid,
            'return_format' => 'array',
            'records'       => array($record),
            'fields'        => array_values(array_unique(array_merge(array($ins->key_field), $ins->hereFields()))),
            'events'        => array_values(array_unique(array($keyEventId, $eventId))),
        ));
        $row = $data[$record][$eventId] ?? array();
        if ($repeatKey !== null) {
            $row = array_merge($row, $data[$record]['repeat_instances'][$eventId][$repeatKey][$instance] ?? array());
        }
        if ($ins->key_field === $proj->table_pk) {
            $keyValue = $record; // the record ID is the same in every event and instance
        } elseif ($keyEventId === $eventId) {
            $keyValue = (string) ($row[$ins->key_field] ?? '');
        } else {
            $keyValue = (string) ($data[$record][$keyEventId][$ins->key_field] ?? '');
        }
        return array($keyValue, $row);
    }

    /** Push: copy the saved record's values to every matching record in the other project. */
    protected function runPush(UpdateInstruction $ins, string $record, int $eventId, string $instrument, int $instance, bool $quiet = false): array {
        $pid = $ins->project_id;
        $repeatKey = $this->repeatKey($this->getProj($pid), $eventId, $instrument);
        list($keyValue, $row) = $this->savedSide($ins, $record, $eventId, $repeatKey, $instance);
        $counts = array('updated' => 0, 'failed' => 0, 'skipped' => 0);
        if ($keyValue === '') return $counts; // record isn't linked to anything - nothing to do, nothing to log

        // Copied values come from the event (and repeating instance) that was just saved. A
        // blank value maps to '' and is written with 'overwrite', so clearing a field here
        // clears it there too.
        $wanted = $ins->buildValues($row);

        $otherPid = $ins->other_project_id;
        $skipped = array();
        $matches = $this->findInOther($ins, $keyValue, $ins->thereFields(), $skipped);

        $updated = 0;
        $updatedRecords = array();
        $changedFields = array();
        $errors = array();
        $lockedNotes = array();
        foreach ($matches as list($otherRecord, $otherEventId, $row, $otherRepeatKey, $otherInstance)) {
            $changed = $ins->valuesToWrite($wanted, $row); // differing values, except "only if empty" fields already filled there
            if (empty($changed)) continue;
            $result = $this->saveAs($ins, $otherPid, $otherRecord, $otherEventId, $changed, $pid, $record, $otherRepeatKey, $otherInstance);
            if (!empty($result['locked'])) {
                // Locked data is left alone, as REDCap does: expected, not a failure.
                $lockedNotes[] = "record {$otherRecord}".($otherRepeatKey !== null ? " instance {$otherInstance}" : '').": ".implode(', ', $result['locked']);
            }
            if (!empty($result['errors'])) {
                $errors[] = "record {$otherRecord}: ".implode('; ', (array) $result['errors']);
            } elseif (!empty($result['written'])) {
                $updated++;
                $updatedRecords[$otherRecord] = true;
                $changedFields += array_fill_keys(array_keys($result['written']), true);
                // Written into the very record, event and instance just saved (e.g. var1 -> var2
                // on the same survey page): keep a public survey's page 1 re-submission in step.
                if ($otherPid === $pid && $otherRecord === $record && (int) $otherEventId === $eventId
                        && ($otherRepeatKey === null ? 1 : $otherInstance) === ($repeatKey === null ? 1 : $instance)) {
                    $this->keepSurveyPageInStep($result['written'], $eventId);
                }
            }
        }
        $updatedRecords = array_map('strval', array_keys($updatedRecords));
        sort($updatedRecords, SORT_NATURAL);
        if ($updated > 0) {
            $this->logPushInProjectLogging($ins, $record, $eventId, array_keys($changedFields), $updatedRecords);
        }

        $details = array(
            'key'             => "{$ins->key_field} = {$keyValue}",
            'other_project'   => "pid {$otherPid}",
            'records_matched' => count($matches) + count($skipped),
            'records_updated' => $updated,
            'fields'          => implode(', ', $ins->thereFields()),
        );
        if (!empty($skipped)) $details['skipped_by_condition'] = count($skipped).' (record'.(count($skipped) === 1 ? '' : 's').' '.implode(', ', $skipped).')';
        // Full list here, since REDCap's Logging Action column may cut it short.
        if (!empty($updatedRecords)) $details['updated_records'] = implode(', ', $updatedRecords);
        if (!empty($lockedNotes)) $details['not_updated_locked'] = implode(' | ', $lockedNotes);
        if ($this->logValues($ins)) $details['values'] = json_encode($wanted);

        $counts = array('updated' => count($updatedRecords), 'failed' => empty($errors) ? 0 : 1, 'skipped' => count($skipped));
        if (empty($errors)) {
            // "Run now" doesn't log a push that changed nothing, or one run would log every
            // record - unless something was left alone because it's locked.
            if (!$quiet || $updated > 0 || !empty($lockedNotes)) $this->logRun('success', $ins, $record, $details);
        } else {
            $details['error'] = implode(' | ', $errors);
            $this->logRun('fail', $ins, $record, $details);
            $this->sendFailureAlert($ins, $record, $details['error']);
        }
        return $counts;
    }

    /**
     * Pull: copy values from the one matching record in the other project into the saved
     * record - into the event, and for a repeating form or event the instance, just saved.
     */
    protected function runPull(UpdateInstruction $ins, string $record, int $eventId, string $instrument, int $instance): array {
        $pid = $ins->project_id;
        $repeatKey = $this->repeatKey($this->getProj($pid), $eventId, $instrument);
        list($keyValue, $row) = $this->savedSide($ins, $record, $eventId, $repeatKey, $instance);
        $none = array('updated' => 0, 'failed' => 0, 'skipped' => 0);
        if ($keyValue === '') return $none; // not linked (yet, or unlinked on purpose) - nothing to do

        $skipped = array();
        $matches = $this->findInOther($ins, $keyValue, $ins->thereFields(), $skipped);
        if (empty($matches)) return $none; // nothing in the other project has this key value

        $otherRecords = array_values(array_unique(array_column($matches, 0)));
        $details = array('key' => "{$ins->key_field} = {$keyValue}", 'other_project' => "pid {$ins->other_project_id}");
        if (count($otherRecords) > 1) {
            $details['error'] = count($otherRecords)." records match in pid {$ins->other_project_id} (".implode(', ', $otherRecords)."); nothing pulled";
            $this->logRun('fail', $ins, $record, $details);
            $this->sendFailureAlert($ins, $record, count($otherRecords)." records in pid {$ins->other_project_id} match key value {$keyValue} (records ".implode(', ', $otherRecords)."). Nothing was pulled.");
            return array('updated' => 0, 'failed' => 1, 'skipped' => 0);
        }

        // One matching record (if it has the key in several events, the first is used).
        // Values are written into the event (and instance) that was just saved.
        list($otherRecord, , $otherRow) = $matches[0];
        $wanted = $ins->buildValues($otherRow);
        $changed = $ins->valuesToWrite($wanted, $row); // differing values, except "only if empty" fields already filled here
        if (empty($changed)) return $none; // already up to date - nothing written, nothing logged

        $result = $this->saveAs($ins, $pid, $record, $eventId, $changed, $ins->other_project_id, $otherRecord, $repeatKey, $instance);
        $details['other_record'] = $otherRecord;
        $details['fields'] = implode(', ', array_keys($result['written']));
        // Locked data is left alone, as REDCap does: expected, not a failure.
        if (!empty($result['locked'])) $details['not_updated_locked'] = implode(', ', $result['locked']);
        if ($this->logValues($ins)) $details['values'] = json_encode($result['written']);

        if (empty($result['errors'])) {
            $this->keepSurveyPageInStep($result['written'], $eventId);
            $this->logRun('success', $ins, $record, $details);
            return array('updated' => empty($result['written']) ? 0 : 1, 'failed' => 0, 'skipped' => 0);
        }
        $details['error'] = implode('; ', (array) $result['errors']);
        $this->logRun('fail', $ins, $record, $details);
        $this->sendFailureAlert($ins, $record, $details['error']);
        return array('updated' => 0, 'failed' => 1, 'skipped' => 0);
    }

    /**
     * After page 1 of a PUBLIC survey, REDCap redirects the respondent to their private
     * survey link and submits the same $_POST there again (Surveys/index.php,
     * System::redirectAsPost - in v15.9.3 and v17.5.0), to stop a browser re-post creating
     * a duplicate record. Pulled fields that were on that page (e.g. @HIDDEN-SURVEY) were
     * submitted blank, so the re-submission would blank them and the next pull would fill
     * them again - three data changes instead of one. Putting the pulled values into
     * $_POST makes the re-submission carry them instead. Only fields that were on the
     * submitted page, and only for the event being saved, are touched. $_POST dates are
     * already Y-M-D at this point (REDCap converts them back before redirecting), the same
     * format getData() returns.
     */
    protected function keepSurveyPageInStep(array $written, $eventId) {
        if (!defined('PAGE') || PAGE !== 'surveys/index.php') return;
        if ((string) ($_GET['event_id'] ?? '') !== (string) $eventId) return;
        foreach ($written as $field => $value) {
            if (is_array($value)) {
                // A checkbox is posted as one hidden input per choice: "__chk__<field>_RC_<code>"
                // (a "." in the code becomes "|"), holding the code when ticked, or ''.
                foreach ($value as $code => $ticked) {
                    $key = "__chk__{$field}_RC_".str_replace('.', '|', (string) $code);
                    if (array_key_exists($key, $_POST)) $_POST[$key] = $ticked === '1' ? (string) $code : '';
                }
            } elseif (array_key_exists($field, $_POST)) {
                $_POST[$field] = $value;
            }
        }
    }

    /**
     * One row in the SOURCE project's Logging page for a push that changed something, e.g.
     * Username "Update Corresponding Records (#2 record 2)", Action "Updated responses in
     * PID 27", List of Data Changes "dcname, dcemail (records 3, 4, 10)". Field names
     * only: the values appear in the other project's Logging, row by row. The row isn't
     * attached to the saved record (Logging would add "Record 2" under the Action), so the
     * record appears only in the Username and Logging's record filter doesn't list the row.
     * Uses REDCap's Logging::logEvent() because REDCap::logEvent() can't set the username;
     * its signature is the same in v15.9.3 and v17.5.0.
     */
    protected function logPushInProjectLogging(UpdateInstruction $ins, string $record, $eventId, array $fields, array $records) {
        \Logging::logEvent(
            '', '', 'OTHER', null,
            implode(', ', $fields).' (record'.(count($records) === 1 ? '' : 's').' '.implode(', ', $records).')',
            "Updated responses in PID {$ins->other_project_id}",
            '', mb_substr(self::EM_NAME." (#{$ins->sequence} record {$record})", 0, 255),
            $ins->project_id, true, $eventId
        );
    }

    /**
     * Write changed values into one record/event. 'overwrite' so blanks clear values, and
     * the Logging/Data History username names this module and where the values came from.
     *
     * Locked data is left alone, as REDCap does: fields on a form locked for that record,
     * event and instance aren't written, and nothing is written to a record locked at the
     * record level. That's expected, not a failure; the rest is still written. (REDCap's
     * saveData() would otherwise reject the whole save for the record.)
     * @return array ['errors' => saveData errors, 'written' => field => value actually saved,
     *               'locked' => fields left alone because they're locked]
     */
    protected function saveAs(UpdateInstruction $ins, int $pid, string $record, $eventId, array $values, int $fromPid, string $fromRecord, ?string $repeatKey = null, int $instance = 1): array {
        $plan = $this->buildSaveData($this->getProj($pid), $record, $eventId, $values, $this->lockedForms($pid, $record, (int) $eventId), $repeatKey, $instance);
        if (empty($plan['written'])) return array('errors' => array(), 'written' => array(), 'locked' => $plan['locked']);

        $result = \REDCap::saveData(array(
            'project_id'        => $pid,
            'dataFormat'        => 'array',
            'data'              => $plan['data'],
            'overwriteBehavior' => 'overwrite',
            'loggingUser'       => $ins->loggingUser($fromPid, $fromRecord),
        ));
        $errors = (array) ($result['errors'] ?? array());
        return array('errors' => $errors, 'written' => empty($errors) ? $plan['written'] : array(), 'locked' => $plan['locked']);
    }

    /**
     * The saveData() 'array' data for one record/event, leaving out locked fields and fields
     * whose form isn't designated for that event.
     * Into a repeating instance: fields on the repeating form - or every field, for a
     * repeating event ($repeatKey '') - go in REDCap's 'repeat_instances' branch for that
     * instance; any others, on non-repeating forms in that event, are saved normally and
     * count as instance 1 for locking.
     * @param array|null $locked lockedForms() result: null = the whole record is locked
     * @return array ['data' => saveData data, 'written' => field => value, 'locked' => fields left out]
     */
    protected function buildSaveData($proj, string $record, $eventId, array $values, ?array $locked, ?string $repeatKey, int $instance): array {
        $data = array();
        $written = array();
        $lockedFields = array();
        foreach ($values as $field => $value) {
            $form = (string) ($proj->metadata[$field]['form_name'] ?? '');
            // A field whose form isn't used in this event can't hold data here (REDCap would
            // reject the whole save), e.g. when every event of a record matches its record ID.
            if (!in_array($form, (array) ($proj->eventsForms[$eventId] ?? array()), true)) continue;
            $inInstance = $repeatKey !== null && ($repeatKey === '' || $form === $repeatKey);
            $formInstance = $inInstance ? $instance : 1;
            if ($locked === null || isset($locked["{$form}:{$formInstance}"])) {
                $lockedFields[] = $field;
                continue;
            }
            $written[$field] = $value;
            if ($inInstance) {
                $data[$record]['repeat_instances'][$eventId][$repeatKey][$instance][$field] = $value;
            } else {
                $data[$record][$eventId][$field] = $value;
            }
        }
        return array('data' => $data, 'written' => $written, 'locked' => $lockedFields);
    }

    /**
     * What's locked in one record and event: null when the whole record is locked (record-
     * level locking, per arm), otherwise "form:instance" => true for each locked form.
     * Read with the framework's parameterised query() from REDCap's locking tables (the same
     * in v15.9.3 and v17.5.0), rather than REDCap's internal Locking class.
     */
    protected function lockedForms(int $pid, string $record, int $eventId): ?array {
        $q = $this->query(
            "select 1 from redcap_locking_records r join redcap_events_metadata e on e.arm_id = r.arm_id
             where r.project_id = ? and r.record = ? and e.event_id = ? limit 1",
            array($pid, $record, $eventId)
        );
        if ($q->fetch_assoc()) return null;
        $locked = array();
        $q = $this->query(
            "select form_name, instance from redcap_locking_data where project_id = ? and record = ? and event_id = ?",
            array($pid, $record, $eventId)
        );
        while ($row = $q->fetch_assoc()) {
            $locked["{$row['form_name']}:".max(1, (int) $row['instance'])] = true;
        }
        return $locked;
    }

    /**
     * Records/events in the other project whose key field equals $keyValue, with $fields.
     * For a same-project instruction, the record, event and instance just saved can be a
     * match like any other (e.g. copying var1 to var2 on the same form); copying a field onto
     * itself changes nothing, so isn't written. When the key in
     * the other project is its record ID and an event is set, that record's event matches
     * even before it holds any data. When the instruction has a condition in the other
     * project, only matches where it's true are returned (so for a pull, an inactive
     * duplicate doesn't count); the others go in $skipped. For a push, every repeating
     * instance whose key matches is a match of its own (a pull never reads from one).
     * @return array list of [record, event_id, row, repeat_key|null, instance]
     */
    protected function findInOther(UpdateInstruction $ins, string $keyValue, array $fields, ?array &$skipped = null): array {
        $otherPid = $ins->other_project_id;
        $other = $this->getProj($otherPid);
        $otherEventId = $ins->other_event !== '' ? $this->eventIdFor($other, $ins->other_event) : null;
        $params = array(
            'project_id'    => $otherPid,
            'return_format' => 'array',
            'fields'        => array_values(array_unique(array_merge(array($ins->other_key_field), $fields))),
        );
        if ($otherEventId !== null) $params['events'] = array($otherEventId);

        if ($otherEventId !== null && $ins->other_key_field === $other->table_pk) {
            $matches = array();
            if (\Records::recordExists($otherPid, $keyValue)) {
                $params['records'] = array($keyValue);
                $row = \REDCap::getData($params)[$keyValue][$otherEventId] ?? array();
                $matches[] = array($keyValue, $otherEventId, $row, null, 1);
            }
        } else {
            $filter = self::equalsFilter($ins->other_key_field, $keyValue);
            if ($filter !== null) $params['filterLogic'] = $filter;
            $matches = UpdateInstruction::findMatches(\REDCap::getData($params), $ins->other_key_field, $keyValue, !$ins->isPull());
        }
        $skipped = array();
        if ($ins->other_condition !== '') {
            $kept = array();
            foreach ($matches as $match) {
                // Checked in the event (and instance) where the key matched; null (invalid logic) counts as false.
                if (\REDCap::evaluateLogic($ins->other_condition, $otherPid, $match[0], $match[1],
                        $match[3] === null ? 1 : $match[4], (string) $match[3], (string) $match[3]) === true) {
                    $kept[] = $match;
                } else {
                    $skipped[$match[0]] = true;
                }
            }
            $matches = $kept;
            $skipped = array_keys($skipped);
            sort($skipped, SORT_NATURAL);
            $skipped = array_map('strval', $skipped);
        }
        return $matches;
    }

    /**
     * REDCap logic that narrows getData() to records whose field equals $value, or null
     * when the value contains both quote types. It's only a pre-filter (logic comparisons
     * can be numeric or case-insensitive); findMatches() then compares exactly. Text is
     * wrapped in the other quote type because \' inside a quoted string fails in v15.9.3
     * and v17.0.0.
     */
    public static function equalsFilter(string $field, string $value): ?string {
        if (strpos($value, "'") === false) return "[{$field}] = '{$value}'";
        if (strpos($value, '"') === false) return "[{$field}] = \"{$value}\"";
        return null;
    }

    // =====================================================================
    // Instructions, checks and rights
    // =====================================================================

    /** @return UpdateInstruction[] all instructions configured in $pid, valid or not, enabled or not */
    protected function getInstructions(int $pid): array {
        if (!isset($this->instructionCache[$pid])) {
            $this->instructionCache[$pid] = array();
            foreach (($this->getSubSettings('update-config', $pid) ?: array()) as $i => $config) {
                $instruction = new UpdateInstruction($config, $i, $pid);
                // A blank instruction is a deleted one (see UpdateInstruction::isBlank()). The
                // others keep their position as their number.
                if (!$instruction->isBlank()) $this->instructionCache[$pid][] = $instruction;
            }
        }
        return $this->instructionCache[$pid];
    }

    /** @return \Project|null null when the project doesn't exist or was deleted */
    protected function getProj($pid) {
        $pid = (int) $pid;
        if ($pid <= 0) return null;
        if (!array_key_exists($pid, $this->projectCache)) {
            $proj = null;
            try {
                $candidate = new \Project($pid);
                if (!empty($candidate->project) && empty($candidate->project['date_deleted'])) $proj = $candidate;
            } catch (\Throwable $e) {
                $proj = null;
            }
            $this->projectCache[$pid] = $proj;
        }
        return $this->projectCache[$pid];
    }

    protected function projectTitle($pid): string {
        $proj = $this->getProj($pid);
        return $proj === null ? "(missing project)" : strip_tags((string) $proj->project['app_title']);
    }

    /** Event id for a unique event name in a given project (REDCap::getEventIdFromUniqueEvent only works for the current project). */
    protected function eventIdFor($proj, string $uniqueName) {
        if ($proj === null || $uniqueName === '' || !$proj->longitudinal) return null;
        $eventId = $proj->getEventIdUsingUniqueEventName($uniqueName);
        return is_numeric($eventId) ? (int) $eventId : null;
    }

    /**
     * Everything that stops an instruction from running, beyond its structure: missing or
     * unsupported fields and events, invalid logic, missing other project. Needs no
     * logged-in user, so it's safe on survey saves.
     * @return string[]
     */
    public function instructionIssues(UpdateInstruction $ins): array {
        // Start from the configured pairs; the instrument's fields are added at the end.
        $ins->setResolvedPairs($ins->explicit_pairs);
        $issues = $ins->getConfigErrors();
        $here = $this->getProj($ins->project_id);
        if ($here === null) return array_merge($issues, array("this project not found"));
        if ($ins->copy_instrument !== '') {
            if (!isset($here->forms[$ins->copy_instrument])) {
                $issues[] = "instrument to copy \"{$ins->copy_instrument}\" not found";
            } elseif ($ins->copy_instrument !== $ins->trigger_form) {
                // A repeating instrument can only be copied when it's the trigger form: then
                // its fields come from (push) or go into (pull) the instance just saved.
                foreach ((array) $here->eventsForms as $eventId => $forms) {
                    if (in_array($ins->copy_instrument, (array) $forms, true) && $here->isRepeatingFormOrEvent($eventId, $ins->copy_instrument)) {
                        $issues[] = "instrument to copy \"{$ins->copy_instrument}\" is repeating (or on a repeating event); that's only supported when it's also the trigger form";
                        break;
                    }
                }
            }
        }

        if ($ins->trigger_form !== '' && !isset($here->forms[$ins->trigger_form])) {
            $issues[] = "trigger form \"{$ins->trigger_form}\" not found";
        }
        if ($ins->trigger_logic !== '' && class_exists('\LogicTester') && !\LogicTester::isValid($ins->trigger_logic)) {
            $issues[] = "trigger condition isn't valid REDCap logic";
        }
        // This project's side: fields on the trigger form may repeat (the instance just saved
        // is used); fields on any other repeating form may not.
        if ($ins->key_field !== '') {
            $issues = array_merge($issues, $this->fieldIssues($here, $ins->key_field, 'key field', '', null, $ins->trigger_form));
        }
        if ($ins->key_event !== '' && $this->eventIdFor($here, $ins->key_event) === null) {
            $issues[] = "key field event \"{$ins->key_event}\" not found";
        }
        foreach ($ins->hereFields() as $field) {
            $issues = array_merge($issues, $this->fieldIssues($here, $field, 'field', '', null, $ins->trigger_form, true));
            if ($ins->isPull()) $issues = array_merge($issues, $this->receivingFieldIssues($here, $field, ''));
        }

        if ($ins->other_project_id <= 0) return $issues;
        $other = $this->getProj($ins->other_project_id);
        $where = "pid {$ins->other_project_id}";
        if ($other === null) return array_merge($issues, array("other project {$where} not found"));

        // The other project's side may not repeat (a push into it, or a pull from it, would
        // have to guess the instance) - checked only in the "Other project event" when one is set.
        $otherEventId = $ins->other_event !== '' ? $this->eventIdFor($other, $ins->other_event) : null;
        if ($ins->other_key_field !== '') {
            $issues = array_merge($issues, $this->fieldIssues($other, $ins->other_key_field, 'key field', $where, $otherEventId, $this->otherRepeatingForm($ins, $other)));
        }
        if ($ins->other_event !== '' && $otherEventId === null) {
            $issues[] = "event \"{$ins->other_event}\" not found in {$where}";
        }
        foreach ($ins->thereFields() as $field) {
            $fieldIssues = $this->fieldIssues($other, $field, 'field', $where, $otherEventId, $this->otherRepeatingForm($ins, $other), true);
            if (empty($fieldIssues) && !$ins->isPull()) $fieldIssues = $this->receivingFieldIssues($other, $field, $where);
            $issues = array_merge($issues, $fieldIssues);
        }
        // "Also copy" pairs with a multiple-answer checkbox: both must be checkboxes, with matching choices.
        foreach ($ins->explicit_pairs as $pair) {
            if (!isset($here->metadata[$pair['here']], $other->metadata[$pair['there']])) continue; // already reported
            $problem = $this->checkboxPairProblem($ins, $here, $other, $pair['here'], $pair['there']);
            if ($problem !== null) {
                $issues[] = "field \"{$pair['here']}\" ".($ins->isPull() ? '←' : '→')." \"{$pair['there']}\" in {$where}: {$problem}";
            }
        }
        if ($ins->other_condition !== '') {
            if (class_exists('\LogicTester') && !\LogicTester::isValid($ins->other_condition)) {
                $issues[] = "condition in other project isn't valid REDCap logic";
            }
            // The Configure dialog's logic editor only knows this project's fields, so check
            // the names against the other project here.
            $events = $other->longitudinal ? array_values((array) $other->getUniqueEventNames()) : array();
            foreach (UpdateInstruction::logicNames($ins->other_condition) as $name) {
                if (!isset($other->metadata[$name]) && !in_array($name, $events, true)) {
                    $issues[] = "condition in other project uses \"{$name}\", which isn't a field or event in {$where}";
                }
            }
        }

        if ($ins->copy_instrument !== '' && isset($here->forms[$ins->copy_instrument])) {
            $this->resolveInstrument($ins, $here, $other);
            if (empty($ins->copy_fields) && empty($ins->getConfigErrors())) {
                $repeating = array_filter($ins->not_copied, function ($reason) { return strpos($reason, self::REPEATING_REASON) === 0; });
                if (empty($repeating)) {
                    $issues[] = "nothing to copy: no field on instrument \"{$ins->copy_instrument}\" can be copied, and there are no other fields";
                } elseif ($ins->isPull()) {
                    $issues[] = "nothing to copy: the instrument's fields are on a repeating instrument or event in {$where}, and a pull can't read from repeating data"
                              .($ins->other_event === '' ? "; set Other project event to an event where it doesn't repeat" : '');
                } else {
                    $issues[] = "nothing to copy: the instrument's fields are on a repeating instrument or event in {$where}"
                              .($ins->other_event !== '' ? " (event {$ins->other_event})" : '')
                              .". Repeating data there can only be updated through a key field on that repeating form (every instance whose key matches), not the record ID";
                }
            }
        }
        $this->setCheckboxCodes($ins, $here, $other);
        return $issues;
    }

    /**
     * Add the copy instrument's fields to the instruction's pairs: each field goes to (push)
     * or comes from (pull) the field with the same variable name in the other project.
     * Fields that can't be copied are recorded in $ins->not_copied with the reason; fields
     * left out on purpose, or already covered by an "Also copy" pair, are simply skipped.
     */
    protected function resolveInstrument(UpdateInstruction $ins, $here, $other) {
        $pairs = $ins->explicit_pairs;
        $covered = array_fill_keys(array_column($pairs, 'here'), true);
        $receivingTaken = array_fill_keys($ins->isPull() ? array_column($pairs, 'here') : array_column($pairs, 'there'), true);
        $where = "pid {$ins->other_project_id}";
        $otherEventId = $ins->other_event !== '' ? $this->eventIdFor($other, $ins->other_event) : null;
        $notCopied = array();

        foreach (array_keys((array) ($here->forms[$ins->copy_instrument]['fields'] ?? array())) as $field) {
            if (in_array($field, $ins->leave_out, true) || isset($covered[$field])) continue;
            $reason = $this->instrumentSkipReason($ins, $here, $field);
            if ($reason === null) {
                // The same-named field in the other project
                if (!isset($other->metadata[$field])) {
                    $reason = "no same name in {$where}";
                } elseif (!empty($this->fieldIssues($other, $field, 'field', '', $otherEventId, $this->otherRepeatingForm($ins, $other), true))) {
                    $label = $this->typeLabel($other, $field);
                    $reason = $label === null ? self::REPEATING_REASON." in {$where}" : "can't be used in {$where} ({$label})";
                } elseif (($problem = $this->checkboxPairProblem($ins, $here, $other, $field, $field)) !== null) {
                    $reason = "checkbox doesn't match {$where}: {$problem}";
                } elseif (!$ins->isPull() && ($field === $other->table_pk || $field === $ins->other_key_field)) {
                    $reason = ($field === $other->table_pk ? "record ID" : "key")." in {$where}";
                } elseif (!$ins->isPull() && !empty($this->receivingFieldIssues($other, $field, ''))) {
                    $reason = "calculated in {$where}";
                } elseif (isset($receivingTaken[$field])) {
                    $reason = "already copied into by another field";
                }
            }
            if ($reason !== null) {
                $notCopied[$field] = $reason;
                continue;
            }
            $pairs[] = array('here' => $field, 'there' => $field, 'only_if_empty' => $ins->instrument_only_if_empty);
            $receivingTaken[$field] = true;
        }
        $ins->setResolvedPairs($pairs, $notCopied);
    }

    /**
     * The other project's form that may repeat: for a push, the form holding the other
     * project's key field (each instance whose key matches is updated). A pull never reads
     * from a repeating form, and a record-ID key doesn't identify instances, so null then.
     */
    protected function otherRepeatingForm(UpdateInstruction $ins, $other): ?string {
        if ($ins->isPull() || !isset($other->metadata[$ins->other_key_field]) || $ins->other_key_field === $other->table_pk) return null;
        return $other->metadata[$ins->other_key_field]['form_name'];
    }

    /** Why a field on the copy instrument (this project) is never copied, or null if it can be. */
    protected function instrumentSkipReason(UpdateInstruction $ins, $here, string $field): ?string {
        if ($field === $here->table_pk) return "record ID";
        if ($field === $ins->key_field) return "key";
        if ($field === $ins->copy_instrument.'_complete') return "Complete? status";
        $meta = $here->metadata[$field] ?? null;
        if ($meta === null) return "not found";
        $misc = (string) ($meta['misc'] ?? '');
        switch ($meta['element_type']) {
            case 'descriptive': return "descriptive";
            case 'file':        return ($meta['element_validation_type'] ?? '') === 'signature' ? "signature" : "file upload";
            case 'sql':         return "SQL";
            case 'calc':        return "calculated";
        }
        if (preg_match('/@CALCTEXT/', $misc)) return "@CALCTEXT";
        if (preg_match('/@CALCDATE/', $misc)) return "@CALCDATE";
        return null;
    }

    /**
     * For the "Not copied" list, when fieldIssues() rejected a field to copy: its type if
     * that type can't be copied (file upload, descriptive), or null when the type is fine,
     * which means the field is on a repeating instrument or event.
     */
    protected function typeLabel($proj, string $field): ?string {
        $type = (string) ($proj->metadata[$field]['element_type'] ?? '');
        return $type !== 'checkbox' ? (self::TYPE_LABELS[$type] ?? null) : null;
    }

    /**
     * A field must exist, hold a single value, and not sit on a repeating instrument or
     * event - except on $allowedForm (the trigger form: the instance just saved is used).
     * With $onlyEventId, only that event is checked (the "Other project event"). The record
     * ID is never a repeating problem: it's the same in every event and instance.
     */
    protected function fieldIssues($proj, string $field, string $what, string $where = '', ?int $onlyEventId = null, ?string $allowedForm = null, bool $copied = false): array {
        $in = $where !== '' ? " in {$where}" : '';
        if (!isset($proj->metadata[$field])) return array("{$what} \"{$field}\" not found{$in}");
        $meta = $proj->metadata[$field];
        // A multiple-answer checkbox can be copied (as a whole set), but never be a key.
        $unsupported = $copied ? array_diff(self::UNSUPPORTED_TYPES, array('checkbox')) : self::UNSUPPORTED_TYPES;
        if (in_array($meta['element_type'], $unsupported, true)) {
            return array("{$what} \"{$field}\"{$in} is a ".self::TYPE_LABELS[$meta['element_type']]." field, which isn't supported");
        }
        $form = $meta['form_name'];
        if ($field === $proj->table_pk || ($allowedForm !== null && $form === $allowedForm)) return array();
        foreach ((array) $proj->eventsForms as $eventId => $forms) {
            if ($onlyEventId !== null && (int) $eventId !== $onlyEventId) continue;
            if (in_array($form, (array) $forms, true) && $proj->isRepeatingFormOrEvent($eventId, $form)) {
                $hint = ($where !== '' && $onlyEventId === null && !empty($proj->longitudinal))
                    ? "; set Other project event to an event where it doesn't repeat" : '';
                return array("{$what} \"{$field}\"{$in} is on a repeating instrument or event, which isn't supported{$hint}");
            }
        }
        return array();
    }

    /** A field's choices if it's a multiple-answer checkbox, otherwise null. */
    protected function checkboxChoices($proj, string $field): ?array {
        $meta = $proj->metadata[$field] ?? null;
        if ($meta === null || $meta['element_type'] !== 'checkbox') return null;
        return UpdateInstruction::parseChoices((string) ($meta['element_enum'] ?? ''));
    }

    /**
     * Why a pair can't be copied because of a multiple-answer checkbox, or null. Values go
     * here -> there for a push, there -> here for a pull.
     */
    protected function checkboxPairProblem(UpdateInstruction $ins, $here, $other, string $hereField, string $thereField): ?string {
        $hereChoices = $this->checkboxChoices($here, $hereField);
        $thereChoices = $this->checkboxChoices($other, $thereField);
        return $ins->isPull()
            ? UpdateInstruction::checkboxProblem($thereChoices, $hereChoices)
            : UpdateInstruction::checkboxProblem($hereChoices, $thereChoices);
    }

    /** Tell the instruction which receiving fields are checkboxes, and their choice codes. */
    protected function setCheckboxCodes(UpdateInstruction $ins, $here, $other) {
        $codes = array();
        foreach ($ins->copy_fields as $pair) {
            $to = $ins->isPull() ? $pair['here'] : $pair['there'];
            $choices = $this->checkboxChoices($ins->isPull() ? $here : $other, $to);
            if ($choices !== null) $codes[$to] = array_map('strval', array_keys($choices));
        }
        $ins->setCheckboxCodes($codes);
    }

    /** A field values are copied INTO can't be the record ID or a calculated field. */
    protected function receivingFieldIssues($proj, string $field, string $where): array {
        if (!isset($proj->metadata[$field])) return array();
        $in = $where !== '' ? " in {$where}" : '';
        if ($field === $proj->table_pk) return array("field \"{$field}\"{$in} is the record ID field, so it can't be copied into");
        if ($proj->metadata[$field]['element_type'] === 'calc' || preg_match('/@CALC(TEXT|DATE)/', (string) ($proj->metadata[$field]['misc'] ?? ''))) {
            return array("field \"{$field}\"{$in} is a calculated field, so it can't be copied into");
        }
        return array();
    }

    /**
     * Lock-down checks for saving or importing an instruction: Project Design rights in
     * this project and the other project (admins pass). Needs a logged-in user, so it's
     * only called from validateSettings(), import() and the summary page.
     * @return string[]
     */
    public function lockdownIssues(UpdateInstruction $ins): array {
        if ($this->isSuperUser()) return array();
        $issues = array();
        if (!$this->userHasDesignRights($ins->project_id)) {
            $issues[] = "you need Project Design rights in this project";
        }
        if ($ins->other_project_id > 0 && $ins->other_project_id !== $ins->project_id && !$this->userHasDesignRights($ins->other_project_id)) {
            $issues[] = "you need Project Design rights in the other project \"".$this->projectTitle($ins->other_project_id)."\" (pid {$ins->other_project_id})";
        }
        return $issues;
    }

    /**
     * An instruction the logged-in user can see but not change (read-only in the Configure
     * dialog): its other project exists, isn't this project, and they don't have Project
     * Design rights there. Admins can change everything. An instruction whose other project
     * was deleted isn't locked, so it can be pointed at another project.
     */
    public function isLockedForUser(UpdateInstruction $ins): bool {
        return $ins->other_project_id > 0 && $ins->other_project_id !== $ins->project_id
            && $this->getProj($ins->other_project_id) !== null && !$this->userHasDesignRights($ins->other_project_id);
    }

    /** @return UpdateInstruction[] this project's saved instructions the logged-in user can't change */
    public function lockedInstructions(int $pid): array {
        return array_values(array_filter($this->getInstructions($pid), array($this, 'isLockedForUser')));
    }

    /** Design rights for the logged-in user (admins always pass). False when nobody is logged in. */
    public function userHasDesignRights($pid): bool {
        if (empty($pid) || $this->getProj($pid) === null) return false;
        if ($this->isSuperUser()) return true;
        try {
            $rights = $this->getUser()->getRights((int) $pid);
        } catch (\Throwable $e) {
            return false;
        }
        return is_array($rights) && (string) ($rights['design'] ?? '') === '1';
    }

    // =====================================================================
    // Logging and alerts
    // =====================================================================

    protected function logValues(UpdateInstruction $ins): bool {
        return UpdateInstruction::isTrue($this->getProjectSetting('log-changes', $ins->project_id));
    }

    /**
     * One entry in this project's External Modules > View Logs, e.g.
     * "Update Corresponding Records: push", "...: pull failed", "...: push skipped".
     */
    protected function logRun(string $result, UpdateInstruction $ins, ?string $record, array $details) {
        $suffix = array('success' => '', 'fail' => ' failed', 'skipped' => ' skipped')[$result];
        $params = array(
            'project_id'  => $ins->project_id,
            'instruction' => $ins->label(),
            'direction'   => $ins->direction,
            'result'      => $result,
        );
        if ($record !== null && $record !== '') $params['record'] = $record;
        foreach ($details as $name => $value) {
            $params[$name] = is_scalar($value) ? (string) $value : json_encode($value);
        }
        $this->log(self::EM_NAME.": {$ins->direction}{$suffix}", $params);
        if ($result === 'fail') $this->logFailureInProjectLogging($ins, $record, (string) ($details['error'] ?? 'unknown error'));
    }

    /**
     * A failed push or pull also gets a row on this project's own Logging page, e.g.
     * Username "Update Corresponding Records (#2)", Action "Pull failed from PID 26",
     * List of Data Changes "Record 21: 3 records match in pid 26 (1, 2, 3); nothing pulled".
     * Like the push row, it isn't attached to a record (so the Action has no "Record N"),
     * which is why the record is named in the text.
     */
    protected function logFailureInProjectLogging(UpdateInstruction $ins, ?string $record, string $error) {
        try {
            \Logging::logEvent(
                '', '', 'OTHER', null,
                ($record !== null && $record !== '' ? "Record {$record}: " : '').$error,
                $ins->isPull() ? "Pull failed from PID {$ins->other_project_id}" : "Push failed to PID {$ins->other_project_id}",
                '', self::EM_NAME." (#{$ins->sequence})",
                $ins->project_id
            );
        } catch (\Throwable $e) {
            // The module log entry above already records the failure.
        }
    }

    /** Log and alert for an unexpected error, without ever breaking the user's (or respondent's) save. */
    protected function failRun(UpdateInstruction $ins, string $record, string $error) {
        try {
            $this->logRun('fail', $ins, $record, array('error' => $error));
            $this->sendFailureAlert($ins, $record, $error);
        } catch (\Throwable $e) {
            // Nothing more we can safely do here.
        }
    }

    /** Email this project's failure alert addresses. Never includes data values. */
    protected function sendFailureAlert(UpdateInstruction $ins, ?string $record, string $problem) {
        $pid = $ins->project_id;
        $recipients = array_filter((array) $this->getProjectSetting('fail-alert-email', $pid), function ($email) {
            return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL);
        });
        if (empty($recipients)) return;

        $from = $GLOBALS['project_contact_email'] ?? '';
        if (empty($from)) $from = $GLOBALS['homepage_contact_email'] ?? '';
        $title = $this->projectTitle($pid);
        $otherTitle = $this->projectTitle($ins->other_project_id);
        $subject = self::EM_NAME." failure: {$title} (pid {$pid}), instruction ".$ins->label();

        $here = htmlspecialchars($title)." (pid {$pid})".($record !== null ? ", record ".htmlspecialchars($record) : '');
        $there = htmlspecialchars($otherTitle)." (pid {$ins->other_project_id})";
        $body = "An update failed in the ".self::EM_NAME." module.<br><br>"
              . "Direction: ".($ins->isPull() ? "pull into {$here} from {$there}" : "push from {$here} to {$there}")."<br>"
              . "Instruction: ".htmlspecialchars($ins->label())."<br>"
              . "Problem: ".htmlspecialchars($problem)."<br><br>"
              . "Details are in External Modules &gt; View Logs. Values are never included in this email.";

        foreach ($recipients as $to) {
            \REDCap::email(trim($to), $from, $subject, $body, '', '', 'REDCap', array(), $pid);
        }
    }

    // =====================================================================
    // Export, import and summary page
    // =====================================================================

    /**
     * CSV of this project's instructions, using the user's own REDCap CSV delimiter. Quotes
     * are doubled and there's no escape character (as Excel writes CSV, and as parseCsv()
     * reads it), so a backslash before a quote, e.g. in logic, round-trips unchanged.
     */
    public function export(): string {
        $delimiter = \User::getCsvDelimiter();
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, UpdateInstruction::CSV_COLUMNS, $delimiter, '"', '');
        foreach (($this->getSubSettings('update-config') ?: array()) as $i => $config) {
            if ((new UpdateInstruction($config, $i))->isBlank()) continue; // deleted
            fputcsv($fh, UpdateInstruction::toCsvRow($config), $delimiter, '"', '');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Replace this project's instructions with CSV text (the format export() writes), read
     * in the browser and sent through the module's AJAX request. Needs Project Design rights
     * here. Every instruction is imported switched off with no other project (the CSV's
     * other_project_id is ignored), so only this project's side is checked now; the other
     * project is checked when it's chosen in the Configure dialog. Any problem rejects the
     * whole file and nothing changes.
     * @return string[] errors (empty = success)
     */
    public function import(string $csv): array {
        $pid = (int) $this->getProjectId();
        if ($pid <= 0) return array('Could not detect project');
        if (!$this->userHasDesignRights($pid)) return array('Import needs Project Design rights in this project.');
        // Import replaces every instruction, so it would delete the ones this user can't change.
        $locked = $this->lockedInstructions($pid);
        if (!empty($locked)) {
            return array('Import replaces every instruction, including '.count($locked).' you can\'t change because you don\'t have Project Design rights in their other project ('
                .implode(', ', array_map(function ($ins) { return $ins->label().': pid '.$ins->other_project_id; }, $locked))
                .'). Ask someone with Project Design rights in both projects, or an administrator, to import.');
        }

        $rows = UpdateInstruction::parseCsv($csv, \User::getCsvDelimiter());
        if (count($rows) === 0) return array('File contains no rows');
        if (count($rows) === 1) return array('File contains a header row but no update instructions');

        $errors = array();
        $instructions = array();
        foreach (array_values($rows) as $rowIndex => $row) {
            if ($rowIndex === 0) continue; // header row: column order matters, heading text doesn't
            $fileRow = $rowIndex + 1;
            // Files exported before the two "only if empty" columns were added still import (as overwrite).
            if (count($row) < UpdateInstruction::CSV_MIN_COLUMNS) {
                $errors[] = "Row {$fileRow}: expected ".count(UpdateInstruction::CSV_COLUMNS)." values, found ".count($row).".";
                continue;
            }
            $config = UpdateInstruction::forImport(UpdateInstruction::fromCsvRow($row, $fileRow, $errors));
            $instruction = new UpdateInstruction($config, count($instructions), $pid);
            $instructions[] = $instruction;
            // No other project yet is expected after an import; the summary page lists it as an Issue.
            $issues = array_diff(
                array_unique(array_merge($this->lockdownIssues($instruction), $this->instructionIssues($instruction))),
                array(UpdateInstruction::NO_OTHER_PROJECT)
            );
            foreach ($issues as $issue) $errors[] = "Row {$fileRow}: {$issue}";
        }
        if (!empty($errors)) return $errors;

        // Save in the raw parallel-array shape REDCap stores repeatable sub_settings in
        // (setProjectSetting() with a nested array doesn't persist them reliably).
        $settings = $this->getProjectSettings();
        foreach (array_keys($instructions[0]->getAsModuleSettings()) as $key) $settings[$key] = array();
        unset($settings['pull-enabled']); // retired setting from early v1.0.0 builds
        $settings['update-config'] = array();
        foreach ($instructions as $idx => $instruction) {
            $settings['update-config'][$idx] = 'true';
            foreach ($instruction->getAsModuleSettings() as $key => $value) {
                $settings[$key][$idx] = $value;
            }
        }
        $this->setProjectSettings($settings);
        return array();
    }

    // =====================================================================
    // Run now (after data imports and API updates, which don't fire the save hook)
    // =====================================================================

    const RUN_NOW_BATCH = 10; // records per request, so a large project doesn't time out

    /**
     * Handle one "Run now" request from the summary page (through redcap_module_ajax):
     *  - plan:   which instructions can be run (and why not), and the records to run them for
     *  - batch:  run the chosen instructions for a batch of records; returns counts
     *  - finish: write one module log entry summarising the run
     * Needs Project Design rights here, and each instruction needs them in its other project
     * too - the same lock-down as saving instructions.
     */
    public function runNowRequest(array $post): array {
        $pid = (int) $this->getProjectId();
        if ($pid <= 0 || !$this->userHasDesignRights($pid)) {
            return array('error' => 'Run now needs Project Design rights in this project.');
        }
        $runnable = array();
        $plan = array();
        foreach ($this->getInstructions($pid) as $ins) {
            $reason = null;
            if (!$ins->config_enabled) {
                $reason = 'disabled';
            } elseif (!empty($lockdown = $this->lockdownIssues($ins))) {
                $reason = implode('; ', $lockdown);
            } elseif (!empty($issues = $this->instructionIssues($ins))) {
                $reason = 'has issues: '.implode('; ', $issues);
            } else {
                $runnable[$ins->sequence] = $ins;
            }
            $plan[] = array(
                'sequence'  => $ins->sequence,
                'label'     => $ins->label(),
                'direction' => $ins->direction,
                'other'     => "pid {$ins->other_project_id}",
                'runnable'  => $reason === null,
                'reason'    => $reason,
            );
        }

        switch ($post['run-action'] ?? '') {
            case 'plan':
                return array('instructions' => $plan, 'records' => $this->allRecords($pid), 'batch' => self::RUN_NOW_BATCH);

            case 'batch':
                $chosen = array_intersect_key($runnable, array_flip(array_map('intval', (array) ($post['instructions'] ?? array()))));
                $records = array_slice(array_map('strval', (array) ($post['records'] ?? array())), 0, self::RUN_NOW_BATCH);
                $totals = array();
                foreach ($chosen as $sequence => $ins) {
                    $totals[$sequence] = array('updated' => 0, 'failed' => 0, 'skipped' => 0);
                    foreach ($records as $record) {
                        foreach ($this->savedPlaces($ins, $record) as list($eventId, $instance)) {
                            $counts = $this->runInstruction($ins, $record, $eventId, $ins->trigger_form, $instance, true);
                            foreach ($counts as $k => $n) $totals[$sequence][$k] += $n;
                        }
                    }
                }
                return array('totals' => $totals);

            case 'finish':
                $summary = array();
                foreach ((array) ($post['totals'] ?? array()) as $sequence => $t) {
                    $summary[] = "#{$sequence}: ".(int) ($t['updated'] ?? 0)." updated, ".(int) ($t['failed'] ?? 0)." failed, ".(int) ($t['skipped'] ?? 0)." skipped by condition";
                }
                $this->log(self::EM_NAME.": run now", array(
                    'project_id' => $pid,
                    'records'    => (int) ($post['record_count'] ?? 0),
                    'summary'    => implode('; ', $summary),
                ));
                return array('ok' => true);
        }
        return array('error' => 'Unknown action.');
    }

    /** Every record in a project, in natural order. */
    protected function allRecords(int $pid): array {
        $proj = $this->getProj($pid);
        $records = array_map('strval', array_keys(\REDCap::getData(array('project_id' => $pid, 'return_format' => 'array', 'fields' => array($proj->table_pk)))));
        sort($records, SORT_NATURAL);
        return $records;
    }

    /**
     * Where a record's trigger form has been saved: [event_id, instance] for each event it's
     * designated in (instance 1 when it doesn't repeat; each saved instance when it does).
     */
    protected function savedPlaces(UpdateInstruction $ins, string $record): array {
        $proj = $this->getProj($ins->project_id);
        $places = array();
        foreach ((array) $proj->eventsForms as $eventId => $forms) {
            if (!in_array($ins->trigger_form, (array) $forms, true)) continue;
            $repeatKey = $this->repeatKey($proj, (int) $eventId, $ins->trigger_form);
            if ($repeatKey === null) {
                $places[] = array((int) $eventId, 1);
                continue;
            }
            $data = \REDCap::getData(array(
                'project_id'    => $ins->project_id,
                'return_format' => 'array',
                'records'       => array($record),
                'events'        => array((int) $eventId),
                'fields'        => array($ins->trigger_form.'_complete'),
            ));
            foreach (array_keys((array) ($data[$record]['repeat_instances'][$eventId][$repeatKey] ?? array())) as $instance) {
                $places[] = array((int) $eventId, (int) $instance);
            }
        }
        return $places;
    }

    /**
     * Read-only summary of this project's instructions. Anyone who can open the page can
     * view and export; Import and Edit settings need Project Design rights here.
     * Called from summary.php; its buttons send requests to redcap_module_ajax().
     */
    public function summaryPage() {
        $pid = (int) $this->getProjectId();
        $canDesign = $this->userHasDesignRights($pid);
        $instructions = $this->getInstructions($pid);
        // The framework's JavaScript Module Object: its ajax() requests reach redcap_module_ajax().
        echo $this->initializeJavascriptModuleObject();
        ?>
        <style>
            .ucr-table { table-layout: fixed; font-size: 12px; }
            .ucr-table th { background: #f0f0f0 !important; vertical-align: bottom; }
            .ucr-table td { word-wrap: break-word; }
            .ucr-badge { display: inline-block; color: #fff; padding: 1px 6px; border-radius: 3px; font-size: 11px; margin: 1px 0; }
            .ucr-here { background: #0067b1; } .ucr-there { background: #6c757d; }
            .ucr-push { background: #0d6efd; } .ucr-pull { background: #6f42c1; } .ucr-ins { background: #198754; }
            .ucr-ok { color: #0d8a2f; font-weight: bold; } .ucr-off { color: #b00020; font-weight: bold; }
        </style>
        <h4 style="margin-top:14px;">Update Corresponding Records &ndash; Summary of Update Instructions</h4>
        <p>Update instructions set up in this project. Each runs when its trigger form is saved here. Export or import them as CSV.</p>

        <div style="margin-bottom:10px;">
            <button type="button" class="btn btn-primaryrc btn-sm" id="ucr-export-btn">Export</button>
            <?php if ($canDesign): ?>
                <button type="button" class="btn btn-success btn-sm" id="ucr-import-btn">Import</button>
                <input type="file" id="ucr-import-file" accept=".csv" style="display:none;">
                <a class="btn btn-dark btn-sm" style="color:#fff;" href="<?= htmlspecialchars(APP_PATH_WEBROOT.'ExternalModules/manager/project.php?pid='.$pid.'&ucr_config=1') ?>">Edit settings</a>
                <button type="button" class="btn btn-sm" style="background:#fd7e14;border-color:#fd7e14;color:#fff;" id="ucr-run-btn">Run now&hellip;</button>
            <?php else: ?>
                <span class="text-muted">&nbsp;Import and Edit settings need Project Design rights in this project.</span>
            <?php endif; ?>
        </div>
        <div id="ucr-import-status"><?php if (isset($_GET['ucr_imported'])): ?>
            <div class="alert alert-success" style="max-width:700px;"><b>Imported.</b> Every instruction is off, with no other project.
                In <b>Edit settings</b>, choose each instruction's other project, then tick <b>Enabled</b>.</div>
            <script>history.replaceState({}, null, location.href.split('&ucr_imported')[0]);</script>
        <?php endif; ?></div>

        <?php if (empty($instructions)): ?>
            <p class="text-muted">None set up.</p>
        <?php else: ?>
        <table class="table table-bordered table-sm ucr-table">
            <colgroup><col style="width:4%"><col style="width:5%"><col style="width:12%"><col style="width:8%"><col style="width:11%"><col style="width:14%"><col style="width:12%"><col style="width:22%"><col style="width:12%"></colgroup>
            <thead><tr><th>#</th><th>On</th><th>Description</th><th>Direction</th><th>Trigger (here)</th><th>Key (here &harr; other)</th><th>Other project</th><th>Fields copied</th><th>Not copied</th></tr></thead>
            <tbody>
            <?php foreach ($instructions as $ins): ?>
                <?php $issues = $this->instructionIssues($ins); // also works out which instrument fields are copied ?>
                <tr>
                    <td><?= (int) $ins->sequence ?></td>
                    <td><?= $ins->config_enabled ? '<span class="ucr-ok">&#10003;</span>' : '<span class="ucr-off">&#10007;</span>' ?></td>
                    <td><?= nl2br(htmlspecialchars($ins->config_description)) ?></td>
                    <td><?= $ins->isPull()
                            ? '<span class="ucr-badge ucr-pull">pull</span><br><span class="text-muted">here &larr; other</span>'
                            : '<span class="ucr-badge ucr-push">push</span><br><span class="text-muted">here &rarr; other</span>' ?></td>
                    <td><?= $this->badge($ins->trigger_form, 'ucr-here') ?>
                        <?php if ($ins->trigger_logic !== ''): ?><span class="ucr-badge ucr-there" title="<?= htmlspecialchars($ins->trigger_logic, ENT_QUOTES) ?>">&#9889;</span><?php endif; ?></td>
                    <td><?= $this->badge($ins->key_field, 'ucr-here') ?> &harr; <?= $this->badge($ins->other_key_field, 'ucr-there') ?></td>
                    <td><?= $ins->other_project_id > 0 ? htmlspecialchars($this->projectTitle($ins->other_project_id))." (pid {$ins->other_project_id})" : '&mdash;' ?>
                        <br><span class="text-muted">event: <?= $ins->other_event !== '' ? htmlspecialchars($ins->other_event) : 'any' ?></span>
                        <?php if ($ins->other_condition !== ''): ?><span class="ucr-badge ucr-there" title="<?= htmlspecialchars($ins->other_condition, ENT_QUOTES) ?>">&#9889;</span><?php endif; ?></td>
                    <td><?php
                        $explicit = array_column($ins->explicit_pairs, 'here');
                        $fromInstrument = array_values(array_diff(array_column($ins->copy_fields, 'here'), $explicit));
                        if ($ins->copy_instrument !== ''): ?>
                            <span class="ucr-badge ucr-ins">instrument <?= htmlspecialchars($ins->copy_instrument) ?></span>
                            <?= $this->overwriteIcon($ins->instrument_only_if_empty) ?>
                            <span class="text-muted">(<?= count($fromInstrument) ?> field<?= count($fromInstrument) === 1 ? '' : 's' ?><?= $ins->instrument_only_if_empty ? ', only if empty' : '' ?>)</span><br>
                            <?php foreach ($fromInstrument as $field) echo $this->badge($field, 'ucr-here').' '; ?>
                            <?php if (!empty($ins->leave_out)): ?><br><span class="text-muted">leave out:</span>
                                <?php foreach ($ins->leave_out as $field) echo $this->badge($field, 'ucr-here').' '; ?><?php endif; ?>
                            <?php if (!empty($ins->explicit_pairs)): ?><br><span class="text-muted">also:</span><?php endif; ?>
                        <?php endif; ?>
                        <?php foreach ($ins->explicit_pairs as $pair): ?>
                            <span style="white-space:nowrap;"><?= $this->overwriteIcon(!empty($pair['only_if_empty'])) ?><?= $this->badge($pair['here'], 'ucr-here') ?><?= $ins->isPull() ? '&larr;' : '&rarr;' ?><?= $this->badge($pair['there'], 'ucr-there') ?></span>
                        <?php endforeach; ?></td>
                    <td style="color:#8a6d00;font-size:11px;"><?php
                        $notCopied = array();
                        foreach ($ins->not_copied as $field => $reason) $notCopied[] = htmlspecialchars("{$field} ({$reason})");
                        echo implode('<br>', $notCopied); ?></td>
                </tr>
                <?php if (!empty($issues)): ?>
                <tr><td colspan="9" style="color:#b00020;"><b>Issues (instruction <?= (int) $ins->sequence ?> is skipped until fixed):</b> <?= implode('; ', array_map('htmlspecialchars', $issues)) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-muted" style="margin-top:-10px;margin-bottom:14px;">Blue badges are this project's fields, grey the other project's; the arrow shows which way values are copied. An instrument's fields go to (or come from) the same variable names in the other project. "Not copied" is information, not an error. <i class="fa-solid fa-circle-check text-primary"></i> = can overwrite, <i class="fa-solid fa-times-circle text-secondary"></i> = only copied into empty fields. &#9889; = has a condition (hover to see it): next to the trigger form for this project, next to the other project for the other project.</div>
        <?php endif; ?>

        <script>
        // All requests go through the framework's module object (login, declared actions,
        // CSRF token and project are checked by the framework before redcap_module_ajax runs).
        var ucrModule = <?= $this->getJavascriptModuleObjectName() ?>;
        var ucrRequest = function(action, payload){
            return ucrModule.ajax(action, payload || {}).then(function(data){
                if (data && data.error) throw new Error(data.error);
                return data;
            });
        };
        var ucrEsc = function(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;'); };

        (function(){
            // Export: the CSV comes back as text and is saved as a file in the browser.
            document.getElementById('ucr-export-btn').addEventListener('click', function(){
                ucrRequest('export-instructions').then(function(data){
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(new Blob([data.csv], { type: 'text/csv;charset=utf-8' }));
                    link.download = data.filename;
                    document.body.appendChild(link);
                    link.click();
                    setTimeout(function(){ URL.revokeObjectURL(link.href); link.remove(); }, 1000);
                }).catch(function(err){ alert('Export failed: ' + (err && err.message ? err.message : err)); });
            });

            // Import: the CSV is read in the browser and sent as text (no file upload).
            var importBtn = document.getElementById('ucr-import-btn');
            if (!importBtn) return;
            var fileInput = document.getElementById('ucr-import-file');
            var status = document.getElementById('ucr-import-status');
            var showError = function(html){
                status.innerHTML = '<div class="alert alert-danger" style="max-width:700px;">' + html + '</div>';
            };
            importBtn.addEventListener('click', function(){ fileInput.value = ''; fileInput.click(); });
            fileInput.addEventListener('change', function(e){
                var file = e.target.files[0];
                if (!file) return;
                if (!/\.csv$/i.test(file.name)) { showError('<b>Import failed:</b> "' + ucrEsc(file.name) + '" isn\'t a CSV file.'); return; }
                status.innerHTML = '<span class="text-muted">Importing&hellip;</span>';
                file.text().then(function(text){
                    return ucrRequest('import-instructions', { csv: text });
                }).then(function(data){
                    if (data.result === 1) {
                        status.innerHTML = '<span class="text-muted">Imported. Reloading&hellip;</span>';
                        window.location.href = window.location.href.split('&ucr_imported')[0] + '&ucr_imported=1';
                    } else {
                        showError('<b>Import rejected. Nothing was changed.</b><br>' + (data.errors || []).map(ucrEsc).join('<br>'));
                    }
                }).catch(function(err){ showError('<b>Import failed:</b> ' + ucrEsc(err && err.message ? err.message : err)); });
            });
        })();

        // "Run now": run instructions for every record, in batches, e.g. after a data import.
        (function(){
            var runBtn = document.getElementById('ucr-run-btn');
            if (!runBtn) return;
            var esc = ucrEsc;
            var post = function(params){ return ucrRequest('run-now', params); };
            var running = false;
            window.addEventListener('beforeunload', function(e){ if (running) { e.preventDefault(); e.returnValue = ''; } });

            runBtn.addEventListener('click', function(){
                var $dlg = $('<div style="font-size:13px;line-height:1.4;"><span class="text-muted">Loading&hellip;</span></div>');
                var close = function(){ $dlg.dialog('close'); };
                $dlg.dialog({ title: 'Run update instructions now', modal: true, width: 560, close: function(){ $dlg.remove(); } });

                post({ 'run-action': 'plan' }).then(function(plan){
                    var html = '<p>Runs the chosen instructions for every record in this project, as if each record\'s trigger form had just been saved. '
                             + 'Use it after a data import or an API update, which don\'t run instructions by themselves.</p>';
                    plan.instructions.forEach(function(ins){
                        html += '<div style="margin:2px 0;"><label style="font-weight:normal;' + (ins.runnable ? '' : 'color:#777;') + '">'
                              + '<input type="checkbox" class="ucr-run-ins" value="' + ins.sequence + '"' + (ins.runnable ? ' checked' : ' disabled') + ' style="margin-right:6px;">'
                              + esc(ins.label) + ' <span class="text-muted">(' + esc(ins.direction) + ' ' + (ins.direction === 'pull' ? 'from ' : 'to ') + esc(ins.other) + ')</span>'
                              + (ins.runnable ? '' : '<br><span style="color:#a00;margin-left:20px;">Can\'t be run: ' + esc(ins.reason) + '</span>')
                              + '</label></div>';
                    });
                    html += '<p class="text-muted" style="margin-top:8px;">Trigger conditions and conditions in the other project still apply, and only values that differ are written. '
                          + plan.records.length + ' record' + (plan.records.length === 1 ? '' : 's') + '; this may take a minute. Keep this page open until it finishes.</p>';
                    $dlg.html(html);
                    $dlg.dialog('option', 'buttons', [
                        { text: 'Cancel', click: close },
                        { text: 'Run', click: function(){
                            var chosen = $dlg.find('.ucr-run-ins:checked').map(function(){ return parseInt(this.value, 10); }).get();
                            if (!chosen.length) { alert('Tick at least one instruction to run.'); return; }
                            run(plan, chosen);
                        } }
                    ]);
                }).catch(function(err){
                    $dlg.html('<div style="color:#a00;">' + esc(err.message) + '</div>');
                    $dlg.dialog('option', 'buttons', [{ text: 'Close', click: close }]);
                });

                var run = function(plan, chosen){
                    running = true;
                    $dlg.dialog('option', 'buttons', []);
                    var records = plan.records, size = plan.batch, totals = {}, done = 0;
                    chosen.forEach(function(s){ totals[s] = { updated: 0, failed: 0, skipped: 0 }; });
                    var progress = function(){
                        var pct = records.length ? Math.round(100 * done / records.length) : 100;
                        $dlg.html('Running&hellip; records ' + (done + 1 > records.length ? records.length : done + 1) + '&ndash;' + Math.min(done + size, records.length) + ' of ' + records.length
                            + '<div style="height:16px;background:#e9ecef;border-radius:4px;overflow:hidden;margin:8px 0;"><div style="height:100%;width:' + pct + '%;background:#337ab7;"></div></div>'
                            + '<span class="text-muted">Keep this page open.</span>');
                    };
                    var next = function(){
                        if (done >= records.length) return finish();
                        progress();
                        post({ 'run-action': 'batch', instructions: chosen, records: records.slice(done, done + size) })
                            .then(function(res){
                                Object.keys(res.totals || {}).forEach(function(s){
                                    ['updated', 'failed', 'skipped'].forEach(function(k){ totals[s][k] += res.totals[s][k]; });
                                });
                                done += size;
                                next();
                            })
                            .catch(stop);
                    };
                    var finish = function(){
                        post({ 'run-action': 'finish', totals: totals, record_count: records.length })
                            .then(function(){
                                running = false;
                                var rows = '';
                                plan.instructions.forEach(function(ins){
                                    if (!totals[ins.sequence]) return;
                                    var t = totals[ins.sequence];
                                    rows += '<tr><td>' + esc(ins.label) + '</td><td>' + t.updated + '</td><td>' + t.failed + '</td><td>' + t.skipped
                                          + (t.skipped ? ' <span class="text-muted">(condition)</span>' : '') + '</td></tr>';
                                });
                                $dlg.html('<b>Finished: ' + records.length + ' record' + (records.length === 1 ? '' : 's') + ' processed.</b>'
                                    + '<table class="table table-bordered table-sm" style="margin-top:6px;font-size:12px;"><thead><tr><th>Instruction</th><th>Records updated</th><th>Failed</th><th>Skipped</th></tr></thead><tbody>'
                                    + rows + '</tbody></table>'
                                    + '<div class="text-muted">Each change is in the projects\' Logging as usual. Details are in External Modules &gt; View Logs, plus one "run now" entry naming who ran it.</div>');
                                $dlg.dialog('option', 'buttons', [{ text: 'Close', click: close }]);
                            })
                            .catch(stop);
                    };
                    var stop = function(err){
                        running = false;
                        $dlg.html('<div style="color:#a00;"><b>Stopped after ' + Math.min(done, records.length) + ' of ' + records.length + ' records:</b> ' + esc(err.message)
                            + '</div><div class="text-muted" style="margin-top:6px;">Records already processed keep their changes; running again is safe (only differences are written).</div>');
                        $dlg.dialog('option', 'buttons', [{ text: 'Close', click: close }]);
                    };
                    next();
                };
            });
        })();
        </script>
        <?php
    }

    protected function badge(string $text, string $class): string {
        if ($text === '') return '<span class="text-muted">&mdash;</span>';
        return '<span class="ucr-badge '.$class.'">'.htmlspecialchars($text).'</span>';
    }

    /** Copy Data on Save's icons: blue tick = can overwrite, grey cross = only into empty fields. */
    protected function overwriteIcon(bool $onlyIfEmpty): string {
        return $onlyIfEmpty
            ? '<i class="fa-solid fa-times-circle text-secondary" title="Only if empty"></i> '
            : '<i class="fa-solid fa-circle-check text-primary" title="Can overwrite"></i> ';
    }
}
