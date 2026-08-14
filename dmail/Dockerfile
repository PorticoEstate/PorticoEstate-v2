FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
EXPOSE 80
ENV PORT=80
CMD ["sh", "entrypoint.sh"]
