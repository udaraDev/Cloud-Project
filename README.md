# 🏔️ KnucklesProducts — Cloud-Native E-Commerce Platform

![CI Pipeline](https://github.com/udaraDev/Cloud-Project/actions/workflows/ci.yml/badge.svg)
![Architecture](https://img.shields.io/badge/Architecture-Microservices-blue)
![Deployment](https://img.shields.io/badge/Deployment-Azure_AKS-0078D4)

**KnucklesProducts** is a modern e-commerce platform for handcrafted artisan products from the Knuckles mountain range of Sri Lanka. The platform allows users to browse products, manage their shopping cart, and place orders with multiple payment options. It features Google OAuth authentication, a responsive UI, and a fully asynchronous checkout pipeline where inventory updates and email notifications are processed in the background — ensuring a fast, seamless user experience.

Built as a cloud-native application for the **EE7222 Cloud Computing** module, the project demonstrates how a real-world web application can be decomposed into independently deployable microservices, containerized with Docker, orchestrated via Kubernetes, and deployed to Azure AKS.

---

## 🐳 Running Locally (Docker Compose)

You can spin up the entire microservices cluster on your local machine using Docker Compose.

### Prerequisites
* [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.
* Git

### Setup Instructions

1. **Clone the repository:**
   ```bash
   git clone https://github.com/udaraDev/Cloud-Project.git
   cd Cloud-Project
   ```

2. **Start the cluster:**
   ```bash
   # This will build the images and start all 6 containers in the background
   docker compose up -d --build
   ```

3. **Initialize the Database:**
   ```bash
   # Run migrations
   docker compose exec app php artisan migrate --force

   # Seed the database with products
   docker compose exec app php artisan db:seed --class=ProductSeeder --force
   ```

4. **Access the Application:**
   * **Web App:** http://localhost
   * **Notification API Health:** http://localhost:3001/health
   * **Deep Health Check:** http://localhost/healthz

5. **Test the Async Pipeline:**
   Place an order on the website, then watch the worker and notification logs in real-time:
   ```bash
   docker logs knuckles-queue-worker -f
   # In a separate terminal:
   docker logs knuckles-notification -f
   ```

6. **Stop the cluster:**
   ```bash
   docker compose down
   ```

---

## 🏗️ Cloud-Native Architecture

This project implements a **Polyglot Microservices Architecture** consisting of **6 distinct, containerized services**:

| # | Service | Technology | Role |
|---|---|---|---|
| 1 | **Nginx Reverse Proxy** | Nginx Alpine | HTTP routing, load balancing, static asset serving, security headers |
| 2 | **Laravel Core App** | PHP 8.2-FPM | Authentication, product catalog, cart, checkout, async job dispatch |
| 3 | **Queue Worker** | PHP 8.2 (shared image) | Background order processing, inventory updates, Redis event publishing |
| 4 | **Notification Service** | Node.js 20 + Express | Independent microservice — subscribes to Redis Pub/Sub for email notifications |
| 5 | **MySQL Database** | MySQL 8.0 | Persistent relational storage for products, users, and orders |
| 6 | **Redis Broker** | Redis 7 Alpine | Triple-duty: session store, cache, and message broker (queues + pub/sub) |

### 🔄 Event-Driven Communication Pipeline

The application uses **four communication patterns** to demonstrate distributed systems concepts:

```
① Synchronous HTTP     Browser → Nginx → PHP-FPM → MySQL
② Async Message Queue   Checkout → Redis Queue → Queue Worker
③ Event-Driven Pub/Sub  Worker → Redis PUBLISH → Node.js SUBSCRIBE → Email
④ REST API              Service → HTTP → Notification API
```

**Checkout Flow:**
1. **HTTP Request** — User places an order (fast, ~200ms response)
2. **Message Queue** — Laravel pushes `SendOrderConfirmationJob` and `UpdateInventoryJob` to Redis queues
3. **Queue Worker** — Background worker picks up jobs, processes inventory, publishes `order:confirmed` event
4. **Pub/Sub Event** — The Node.js Notification Service receives the event and sends the confirmation email

### ☁️ Azure AKS Deployment

The project includes production-ready Kubernetes manifests in the `/k8s` directory:

| Manifest | Purpose |
|---|---|
| `configmap.yaml` | Non-sensitive environment configuration |
| `secrets.yaml` | Database credentials, API keys, OAuth secrets |
| `app-deployment.yaml` | Laravel app + Nginx sidecar (2 replicas, HPA auto-scaling 2–10 pods) |
| `queue-worker-deployment.yaml` | Queue workers (2 replicas, HPA 1–5 pods) |
| `notification-deployment.yaml` | Node.js notification service |
| `mysql-deployment.yaml` | MySQL pod + PersistentVolumeClaim (5Gi) |
| `redis-deployment.yaml` | Redis pod + PersistentVolumeClaim (1Gi) |
| `ingress.yaml` | NGINX Ingress Controller with rate limiting |
| `network-policy.yaml` | Least-privilege pod-to-pod communication rules |

**Deploy to AKS:**
```bash
# Apply configurations
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/secrets.yaml
kubectl apply -f k8s/network-policy.yaml

# Deploy stateful services
kubectl apply -f k8s/mysql-deployment.yaml
kubectl apply -f k8s/redis-deployment.yaml

# Deploy application stack
kubectl apply -f k8s/app-deployment.yaml
kubectl apply -f k8s/queue-worker-deployment.yaml
kubectl apply -f k8s/notification-deployment.yaml
kubectl apply -f k8s/ingress.yaml
```

> Full deployment guide: [`docs/aks-deployment-guide.md`](docs/aks-deployment-guide.md) | Architecture deep-dive: [`docs/architecture.md`](docs/architecture.md)

### 🛡️ CI/CD Pipeline

The repository uses **GitHub Actions** for continuous integration and deployment:

| Pipeline | Trigger | What It Does |
|---|---|---|
| **CI** (`ci.yml`) | Push to `main`, `feature/*`, PRs | Pest tests, security audit, code style check, Docker build verification |
| **CD** (`cd.yml`) | Push to `main` | Build images → Push to ACR → Deploy to AKS → Run migrations |

---

## 👥 Group Members

| Registration No. | Name |
|---|---|
| EG/2021/4614 | Kodithuwakku K.K.A.M |
| EG/2021/4613 | Kodikara A.W |
| EG/2020/4017 | Kavindi E.H.S |
| EG/2021/4805 | Senevirathna P.U.S |

**Module:** EE7222 — Cloud Computing  
**Department:** Electrical & Information Engineering  
**University of Ruhuna**

---
*Handcrafted for Cloud Computing Module* 🏔️
