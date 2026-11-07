# Build with additional context "app" = the php-fpm image (see docker-compose.yml),
# so nginx serves exactly the assets that were compiled into the app image.
FROM nginx:alpine
RUN rm -f /etc/nginx/conf.d/default.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /app/public /app/public
