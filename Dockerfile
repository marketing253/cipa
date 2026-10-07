# Urna CIPA — imagem de produção (EasyPanel reconstrói a cada push na main)
#
# Raiz pública do Apache = public/. app/ (código e a tela) e /data (banco SQLite)
# ficam fora dela. /data PRECISA ser um volume: sem ele os VOTOS somem a cada deploy.
FROM php:8.2-apache

ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN docker-php-ext-install -j"$(nproc)" opcache && a2enmod headers
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/seguranca.conf /etc/apache2/conf-available/seguranca.conf
RUN a2enconf seguranca
COPY docker/php.ini /usr/local/etc/php/conf.d/urna.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY app/ /var/www/html/app/
COPY public/ /var/www/html/public/

ENV CIPA_DATA=/data
VOLUME ["/data"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/ > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]