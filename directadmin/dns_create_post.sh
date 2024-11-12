#!/bin/bash
if [ -z "$domain" ]; then
    echo "unlimited-name-servers: Error - No domain provided in environment variable" >> /var/log/messages
    exit 1
fi

echo "unlimited-name-servers: Domain passed from environment: $domain" >> /var/log/messages
php /usr/local/directadmin/scripts/custom/unlimited-name-servers.php "$domain"


