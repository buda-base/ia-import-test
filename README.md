# Test suite for IA's import script

The main file is [RawBDRCBookPreprocessor.inc.php](RawBDRCBookPreprocessor.inc.php) which is put in production by Internet Archive as part of their pipeline to import BDRC's data. This repository is a test suite for this script to make sure that:
- it handles all the use cases of access (restrictions, digital lending, etc.)
- does so for all the data formats that have been sent to IA

The reason behind this second requirement is that IA needs to be able to re-import some files that BDRC sent in the past, and these may be in legacy formats.

The BDRC formats we handle are:
- [tbrc/](tbrc/): the format used to export from tbrc.org
- [buda1/](buda1/): the format used to export from BUDA (starting 2021)

## Dependencies

TODO

## Running

TODO