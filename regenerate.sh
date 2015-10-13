#!/bin/sh
# Script to regenerate all local data files for railservice
# Must be run from the top directory of the railservice application
php -f _editor/generator.php -- names
php -f _editor/generator.php -- near
php -f _editor/generator.php -- prim
php -f _editor/generator.php -- links
php -f _editor/generator.php -- stops
php -f _editor/generator.php -- p2p
php -f _editor/generator.php -- walk
#This needs to be run a second time to allow it to contain walk data
php -f _editor/generator.php -- p2p
exit 0
