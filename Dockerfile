FROM novosga/novosga:latest

WORKDIR /var/www/html

# Custom module and app overrides
COPY src/AudienciaBundle /var/www/html/src/AudienciaBundle
COPY templates/audiencia /var/www/html/templates/audiencia
COPY translations/AudienciaBundle.pt_BR.yaml /var/www/html/translations/AudienciaBundle.pt_BR.yaml
COPY config/bundles.php /var/www/html/config/bundles.php
COPY config/services.yaml /var/www/html/config/services.yaml

