# makefile for cap-rel laravel projects
#
# Makefile.local, when a site has one, carries THAT site: accounts, binaries,
# paths, as := assignments. Never a recipe. It is read first, then the shared
# chain below is read too -- always -- so a local file can no longer silently
# freeze the recipes of the day it was written.
-include Makefile.local
include Makefile.dist

# `make` alone shows the list of targets, it does not deploy. The full chain
# stays one word away (`make all`), but it takes the site down, runs composer
# and touches /etc through sudo: not something a bare `make`, typed out of
# habit in the wrong checkout, should start.
.DEFAULT_GOAL := help
