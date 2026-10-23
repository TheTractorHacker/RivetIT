#!/usr/bin/env bash
# After build-demo.sh: add the QA-UI- training accounts, start the server on 127.0.0.1:8191.
set -u
export MYSQL_ROOT=/home/sysadmin/demos/mysqlroot.sh
DB=${QA_DB:-qa_sweep_demo}; APP=${QA_APP:-/home/sysadmin/qa-sweep-app}; PORT=${QA_PORT:-8191}
# build-demo.sh now completes on its own (seed 85 skips the backup when no passphrase exists)
(cd $APP/scripts && php update_cli.php --update_db 2>&1 | tail -1)
$MYSQL_ROOT $DB 2>&1 <<'SQL' | grep -i "^ERROR"
insert into user_roles (role_name,role_description,role_is_admin) values ('QA-UI-Training-L2','qa',0),('QA-UI-Training-L3','qa',0);
set @r2=(select role_id from user_roles where role_name='QA-UI-Training-L2'); set @r3=(select role_id from user_roles where role_name='QA-UI-Training-L3');
insert into user_role_permissions select @r2,module_id,2 from modules where module_name in ('module_training','module_support','module_client');
insert into user_role_permissions select @r3,module_id,3 from modules where module_name in ('module_training','module_training_kiosk','module_support','module_client');
insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type) select 'QA-UI- Training L2','qa.l2@summitridge.example',user_password,1,@r2,1 from users where user_id=10;
insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type) select 'QA-UI- Training L3','qa.l3@summitridge.example',user_password,1,@r3,1 from users where user_id=10;
insert into user_settings (user_id) select user_id from users where user_email like 'qa.l%';
SQL
for i in 3 4 5; do $MYSQL_ROOT $DB -e "insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key) select 'QA-UI- Admin $i','qa.admin$i@summitridge.example',user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key from users where user_id=9; insert into user_settings (user_id) select user_id from users where user_email='qa.admin$i@summitridge.example'" 2>&1 | grep -i "^ERROR"; done
$MYSQL_ROOT $DB -e "insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key) select 'QA-UI- Admin Dark','qa.admin.dark@summitridge.example',user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key from users where user_id=9; insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key) select 'QA-UI- Tech Dark','qa.tech.dark@summitridge.example',user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key from users where user_id=10; insert into user_settings (user_id,user_config_theme_dark) select user_id,1 from users where user_email like 'qa.%dark%'; update user_settings set user_config_theme_dark=2 where user_id in (9,10)" 2>&1 | grep -i "^ERROR"
(cd $APP && PHP_CLI_SERVER_WORKERS=32 setsid nohup php -S 127.0.0.1:$PORT -t $APP /home/sysadmin/demos/router.php >/home/sysadmin/qa-sweep-server-$PORT.log 2>&1 < /dev/null &)
sleep 2; curl -s -o /dev/null -w "login:%{http_code}\n" http://127.0.0.1:$PORT/login.php
