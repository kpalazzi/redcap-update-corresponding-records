# Change Log
All notable changes to Update Corresponding Records will be documented in this file.


## [1.0.0] - 2026-11-12
### Summary
 - First release of Update Corresponding Records.
 - Each instruction runs when its trigger form is saved in the project it's set up in, and is either a push or a pull.
 - Push: copies values to every matching record in another project (or the same project), matched on a key field that doesn't need to be unique there.
 - Pull: copies values into the saved record from its one matching record in another project. Works on surveys.
 - Trigger condition on the record saved in this project, and an optional condition checked on each matching record in the other project.
 - Repeating trigger forms: pushes copy from, and pulls write into, the instance just saved. Pushes also update every matching repeating instance in the other project when its key field is on that repeating form. Same-project copies to another event of the same record (e.g. a "Most Recent" event).
 - Copy an entire instrument (fields matched by variable name, with a leave-out list), individual fields, or both. Field types that can't be copied are skipped and listed on the summary page.
 - Locked forms and locked records are left alone (not a failure); the rest is still written.
 - Multiple-answer checkboxes are copied as a whole set, only into a checkbox with the same codes and labels.
 - Values are overwritten, including with blanks, unless "Copy only if destination field empty (don't update)" is ticked for the instrument or for an individual field (as in Copy Data on Save). Only values that differ are written.
 - Locked down: saving or importing instructions needs Project Design rights in both projects. Instructions whose other project the user has no Design rights in are shown read-only in the Configure dialog, can't be changed or deleted, and block Import. The other project doesn't need the module enabled.
 - Summary page with configuration checks, CSV export and import (imported instructions come in switched off, with the other project to be chosen), and a Run now button to run instructions for every record (e.g. after a data import, which doesn't run them).
 - One log entry per run, optional logging of values, and optional failure alert emails.
 - Requires REDCap 14.6.4 or later (Module Framework version 16).
