# 🏔️ KnucklesProducts — Cloud-Native E-Commerce Platform

![CI Pipeline](https://github.com/udaraDev/Cloud-Project/actions/workflows/ci.yml/badge.svg)
![Architecture](https://img.shields.io/badge/Architecture-Microservices-blue)
![Deployment](https://img.shields.io/badge/Deployment-Azure_AKS-0078D4)

KnucklesProducts is a modernized, cloud-native e-commerce application demonstrating advanced microservices architecture. Originally a monolithic Laravel application, it has been successfully decomposed into decoupled services using **Docker**, orchestrated via **Kubernetes (AKS)**, and communicates asynchronously via **Redis Pub/Sub** and **Message Queues**.

---

## 🏗️ Cloud-Native Architecture

This project implements a Polyglot Microservices Architecture consisting of **6 distinct, containerized services**:

1. **Nginx Reverse Proxy**: Handles incoming HTTP traffic and routes it to the application.
2. **Laravel Core App (PHP 8.2)**: Manages authentication, product catalog, cart, and checkout logic.
3. **Queue Worker (PHP 8.2)**: Processes heavy asynchronous tasks (e.g., inventory updates) in the background.
4. **Notification Service (Node.js)**: An independent microservice that listens to Redis Pub/Sub events and handles all email/notification logic.
5. **MySQL Database**: Persistent storage for products, users, and orders.
6. **Redis Broker**: Acts as the central nervous system for Session caching, Queue management, and Pub/Sub event broadcasting.

### 🔄 Event-Driven Communication Pipeline

To ensure the application remains highly responsive, heavy operations are offloaded:
- **HTTP Request**: User places an order (Fast, ~200ms response).
- **Message Queue**: Laravel pushes `SendOrderConfirmationJob` and `UpdateInventoryJob` to Redis queues.
- **Queue Worker Processing**: The background worker pulls the jobs and processes inventory updates.
- **Pub/Sub Event**: The worker publishes an `order:confirmed` event to the Redis Broker.
- **Node.js Subscriber**: The independent Notification Service picks up the event and sends the actual email.

---

## 🚀 Key Features

* **Microservices Decomposition**: Core application and notification systems are completely decoupled.
* **Polyglot Environment**: Utilizes the best tool for the job (PHP/Laravel for robust core logic, Node.js for high-throughput event processing).
* **Asynchronous Processing**: Zero UI blocking during checkout; database and email operations happen in the background.
* **Containerized**: Fully Dockerized for parity between local development and production.
* **Azure AKS Ready**: Includes complete Kubernetes ConfigMaps, Secrets, and Deployments customized for budget-optimized Azure execution.
* **CI/CD Pipeline**: GitHub Actions automated pipeline for testing, security audits, and Docker image validation.

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

5. **Test the Async Pipeline:**
   Place an order on the website, then watch the worker and notification logs in real-time:
   ```bash
   docker logs knuckles-queue-worker -f
   # In a separate terminal:
   docker logs knuckles-notification -f
   ```

---

## ☁️ Deploying to Azure Kubernetes Service (AKS)

This project includes production-ready Kubernetes manifests in the `/k8s` directory.

1. Provision an AKS Cluster and Azure Container Registry (ACR).
2. Push the Docker images to your ACR.
3. Apply the Kubernetes manifests:
   ```bash
   # 1. Apply configurations and secrets
   kubectl apply -f k8s/configmap.yaml
   kubectl apply -f k8s/secrets.yaml

   # 2. Deploy stateful services (MySQL, Redis)
   kubectl apply -f k8s/mysql-deployment.yaml
   kubectl apply -f k8s/redis-deployment.yaml

   # 3. Deploy the application stack
   kubectl apply -f k8s/app-deployment.yaml
   kubectl apply -f k8s/queue-worker-deployment.yaml
   kubectl apply -f k8s/notification-service-deployment.yaml
   ```
*(Note: Full detailed deployment instructions can be found in `docs/aks-deployment-guide.md`)*

---

## 🛡️ CI/CD & Automated Testing

The repository uses **GitHub Actions** to enforce continuous integration. On every push to `main` or `feature/*`, the pipeline automatically:
1. Provisions a test environment with MySQL and Redis service containers.
2. Runs the full Pest testing suite (verifying UI endpoints and asynchronous job dispatches).
3. Executes a Composer security audit.
4. Builds and verifies all Docker images across the monorepo.

---

## 📚 Project Evaluation Notes

This project was specifically designed to fulfill advanced Cloud Computing requirements:
- **Scalability**: Redis handles sessions and caching, meaning the `app` pods can be scaled horizontally without session loss.
- **Resiliency**: The worker incorporates fallback mechanisms (try-catch) so local dev works gracefully even if the Redis broker is unavailable.
- **Security**: Hardcoded secrets have been removed; production secrets are injected via `.env.docker` and Kubernetes `Secret` manifests.

---
*Handcrafted for Cloud Computing Module*
