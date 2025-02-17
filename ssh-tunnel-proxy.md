autossh -M 0 -o "ServerAliveInterval 30" -o "ServerAliveCountMax 3" -i ~/paytimum/aws/payment-gateway-key.pem -D 8085 ubuntu@18.206.28.16
