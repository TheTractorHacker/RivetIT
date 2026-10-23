#!/usr/bin/env bash
# Copy the worktree's app files over the running scratch app (keeps its config.php/uploads/rmm state). Override with QA_APP.
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
rsync -a --exclude=.git --exclude=config.php --exclude=uploads --exclude=backups --exclude=tests --exclude=docs --exclude=/vendor --exclude=node_modules "$ROOT"/ "${QA_APP:-/home/sysadmin/qa-sweep-app}"/
