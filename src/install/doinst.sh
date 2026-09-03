( cd usr/local/emhttp/plugins/docktail/event ; rm -rf docker_started ; ln -sf ../restart.sh docker_started )
( cd usr/local/emhttp/plugins/docktail/event ; rm -rf stopping_docker ; ln -sf ../stop.sh stopping_docker )

chmod 0644 /etc/logrotate.d/docktail
chown root:root /etc/logrotate.d/docktail
