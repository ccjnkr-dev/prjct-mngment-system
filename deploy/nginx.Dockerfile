FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build
FROM nginx:stable-alpine
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
