# Resumable location document moves

In **Location Document Move Analysis**, select and save the mappings, then
confirm and click **Execute / Resume**. If the request pauses, times out
or disconnects, reopen the page and use the same button. No reselection
or PHP timeout change is needed.

Keep documents at both locations unchanged until the move finishes. For
filesystem storage the tool creates hard links without overwriting existing
files; the original paths remain usable during **Preparing**. This requires
the source and destination to support hard links on the same filesystem.
Existing destination directories can be merged, but conflicting files and
linked paths block the move.

The VFS path updates and **Cleanup pending** state commit together. Only
then does cleanup unlink the old paths after confirming that their destination
links still refer to the same files. **Already moved: yes** means cleanup
has finished. Check the reported conflict before resuming a blocked move.
An interrupted move from the older non-resumable implementation may require
manual reconciliation first.
