FROM node:20-alpine

WORKDIR /app

# Copy package files first (Docker layer caching)
COPY package.json ./

# Install dependencies
RUN npm install --production

# Copy application code
COPY . .

EXPOSE 3001

# Health check (using wget — available in Alpine by default)
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD wget -qO- http://localhost:3001/health || exit 1

CMD ["node", "index.js"]
