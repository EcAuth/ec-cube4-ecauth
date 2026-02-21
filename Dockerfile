FROM ghcr.io/ec-cube/ec-cube-php:8.3-apache-4.3
COPY docker-entrypoint.sh /docker-entrypoint-plugin.sh
RUN chmod +x /docker-entrypoint-plugin.sh
ENTRYPOINT ["/docker-entrypoint-plugin.sh"]
CMD ["apache2-foreground"]
