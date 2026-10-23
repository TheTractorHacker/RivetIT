#!/usr/bin/env bash
# usage: run_all.sh <outdir>   (needs the scratch server on :8191; ~6 min)
export QA_OUT=$(realpath -m "$1"); mkdir -p "$QA_OUT"; cd "$(dirname "$0")"
cp out/targets.json out/targets_id.json "$QA_OUT"/ 2>/dev/null
L=$QA_OUT/logs; mkdir -p $L
python3 sweep.py admin admin admin_admin > $L/a1.log 2>&1 &
python3 sweep.py admin agent admin_agent > $L/a2.log 2>&1 &
python3 sweep.py tech agent tech_agent > $L/t.log 2>&1 &
QA_TRAINING_ONLY=1 python3 sweep.py l2 agent l2_training > $L/l2.log 2>&1 &
QA_TRAINING_ONLY=1 python3 sweep.py l3 agent l3_training > $L/l3.log 2>&1 &
wait
python3 sweep.py portal client portal_client > $L/p1.log 2>&1 &
python3 sweep.py portal2 client portal2_client > $L/p2.log 2>&1 &
python3 modals.py admin admin > $L/m1.log 2>&1 &
python3 modals.py admin agent > $L/m2.log 2>&1 &
python3 modals.py tech agent > $L/m3.log 2>&1 &
wait
QA_TARGETS=$QA_OUT/targets_id.json python3 sweep.py admin admin,agent id_admin > $L/i1.log 2>&1
QA_TARGETS=$QA_OUT/targets_id.json python3 sweep.py tech agent id_tech > $L/i2.log 2>&1
QA_TARGETS=$QA_OUT/targets_id.json python3 sweep.py portal2 client id_portal2 > $L/i3.log 2>&1
python3 boundary.py > $L/b.log 2>&1
echo finished > $QA_OUT/FINISHED
