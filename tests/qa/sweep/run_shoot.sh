#!/usr/bin/env bash
# usage: run_shoot.sh  (needs scratch server on :8191). Light + dark for admin/tech, light for portal; then flips the global dark default for portal dark.
cd "$(dirname "$0")"; L=/home/sysadmin/qa-design-scratch/logs; mkdir -p $L
for role in admin tech portal; do for vp in 1920x1080 1366x768 768x1024; do python3 design_shoot.py $role light $vp > $L/s_${role}_light_$vp.log 2>&1 & done; done
for role in admin tech; do for vp in 1920x1080 1366x768 768x1024; do python3 design_shoot.py $role dark $vp > $L/s_${role}_dark_$vp.log 2>&1 & done; done
wait
/home/sysadmin/demos/mysqlroot.sh qa_sweep_demo -e "update settings set config_theme_dark_default=1" >/dev/null 2>&1
for vp in 1920x1080 1366x768 768x1024; do python3 design_shoot.py portal dark $vp > $L/s_portal_dark_$vp.log 2>&1 & done
wait
/home/sysadmin/demos/mysqlroot.sh qa_sweep_demo -e "update settings set config_theme_dark_default=0" >/dev/null 2>&1
echo finished > /home/sysadmin/qa-design-scratch/SHOOT_FINISHED
