# makefile for cap-rel laravel projects
#
# Makefile.local, when a site has one, carries THAT site: accounts, binaries,
# paths, as := assignments. Never a recipe. It is read first, then the shared
# chain below is read too -- always -- so a local file can no longer silently
# freeze the recipes of the day it was written.
-include Makefile.local
include Makefile.dist

.DEFAULT_GOAL := all
