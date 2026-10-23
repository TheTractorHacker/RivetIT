#!/usr/bin/env bash
# Copy the worktree's app files over the running scratch app (keeps its config.php/uploads).
rsync -a --exclude=.git --exclude=config.php --exclude=uploads --exclude=tests --exclude=docs --exclude=vendor --exclude=node_modules ${QA_SRC:-/home/sysadmin/qa-sweep-fixes}/ ${QA_APP:-/home/sysadmin/qa-sweep-app}/
