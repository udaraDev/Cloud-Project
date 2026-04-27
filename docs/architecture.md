# KnucklesProducts — Cloud-Native Architecture

## Architecture Overview

KnucklesProducts follows a **container-based microservices architecture** where each component runs as an independently deployable Docker container. Services communicate via **synchronous HTTP** (REST) and **asynchronous messaging** (Redis pub/sub queues).

This architecture is designed around the **Twelve-Factor App** methodology and cloud-native principles:
- **Stateless application tier** — sessions and cache externalized to Redis
- **Backing services as attached resources** — database, cache, and queue are separate services
- **Concurrency via process model** — each service scales independently
- **Disposability** — containers start fast and shut down gracefully

---

## System Architecture Diagram

```mermaid
graph TB
    subgraph "Client Layer"
        Browser["🌐 Web Browser"]
    end

    subgraph "Edge Layer"
        NGINX["🔀 Nginx<br/>Reverse Proxy<br/>Port 80"]
    end

    subgraph "Application Layer"
        APP["🖥️ Laravel App<br/>Web Frontend + API<br/>PHP-FPM :9000"]
        QW["⚙️ Queue Worker<br/>Order Processing<br/>Service"]
    end

    subgraph "Microservices Layer"
        NS["📧 Notification Service<br/>Node.js<br/>Port 3001"]
    end

    subgraph "Data Layer"
        MYSQL[("🗄️ MySQL 8.0<br/>Relational Database<br/>Port 3306")]
        REDIS[("⚡ Redis 7<br/>Cache + Sessions +<br/>Message Broker<br/>Port 6379")]
    end

    subgraph "External Services"
        SMTP["📮 SMTP Server"]
        GOOGLE["🔐 Google OAuth"]
        S3["☁️ S3 Object Storage"]
    end

    Browser -->|"HTTPS"| NGINX
    NGINX -->|"FastCGI"| APP
    APP -->|"SQL Queries"| MYSQL
    APP -->|"Sessions/Cache"| REDIS
    APP -->|"Dispatch Jobs<br/>(Async)"| REDIS
    REDIS -->|"Process Jobs<br/>(BLPOP)"| QW
    QW -->|"SQL Updates"| MYSQL
    QW -->|"PUBLISH event"| REDIS
    REDIS -->|"SUBSCRIBE<br/>(Async)"| NS
    NS -->|"Send Email"| SMTP
    APP -->|"OAuth Flow"| GOOGLE
    APP -.->|"Static Assets<br/>(Production)"| S3

    style NGINX fill:#2d5016,color:#fff
    style APP fill:#1a56db,color:#fff
    style QW fill:#7c3aed,color:#fff
    style NS fill:#dc2626,color:#fff
    style MYSQL fill:#f59e0b,color:#000
    style REDIS fill:#ef4444,color:#fff
```

---

## Service Inventory

| Service | Technology | Role | Container | Scaling Strategy |
|---|---|---|---|---|
| **Nginx** | Nginx Alpine | Reverse proxy, load balancing, static assets, SSL termination | `knuckles-nginx` | Horizontal (multiple upstream backends) |
| **Web App** | Laravel 12 / PHP 8.2 | Frontend rendering, REST API, authentication, cart, checkout | `knuckles-app` | Horizontal (stateless, shared Redis sessions) |
| **Queue Worker** | Laravel / PHP 8.2 | Async order processing, inventory updates, notification dispatch | `knuckles-queue-worker` | Horizontal (multiple workers per queue) |
| **Notification Service** | Node.js 20 / Express | Email notifications via Redis pub/sub subscription | `knuckles-notification` | Horizontal (multiple subscribers) |
| **Database** | MySQL 8.0 | Persistent relational data storage | `knuckles-mysql` | Vertical (or RDS read replicas) |
| **Redis** | Redis 7 Alpine | Sessions, cache, message broker (queue + pub/sub) | `knuckles-redis` | Vertical (or ElastiCache cluster) |

---

## Communication Patterns

### Synchronous Communication (Request-Response)

```mermaid
sequenceDiagram
    participant B as Browser
    participant N as Nginx
    participant A as Laravel App
    participant DB as MySQL

    B->>N: GET /products
    N->>A: Forward request (FastCGI)
    A->>DB: SELECT * FROM products
    DB-->>A: Product data
    A-->>N: HTML Response
    N-->>B: Rendered page
```

### Asynchronous Communication (Event-Driven)

```mermaid
sequenceDiagram
    participant A as Laravel App
    participant R as Redis Queue
    participant QW as Queue Worker
    participant RP as Redis Pub/Sub
    participant NS as Notification Service
    participant SM as SMTP

    A->>R: dispatch(SendOrderConfirmationJob)
    A->>R: dispatch(UpdateInventoryJob)
    Note over A: User gets immediate response

    R-->>QW: BLPOP (picks up job)
    QW->>QW: Process order confirmation
    QW->>RP: PUBLISH "order:confirmed"
    
    R-->>QW: BLPOP (picks up job)
    QW->>QW: Update product stock in DB

    RP-->>NS: SUBSCRIBE receives event
    NS->>SM: Send confirmation email
```

This design ensures:
- **Fast checkout response** — user doesn't wait for email delivery
- **Fault tolerance** — failed jobs are retried automatically (up to 3 times)
- **Independent scaling** — queue workers can scale separately from web servers
- **Loose coupling** — notification service only knows about Redis channels, not Laravel internals

---

## Database Design

### Entity Relationship

```mermaid
erDiagram
    USERS ||--o{ ORDERS : places
    USERS ||--o{ CART_ITEMS : has
    PRODUCTS ||--o{ CART_ITEMS : in
    PRODUCTS ||--o{ ORDER_ITEMS : ordered_as
    ORDERS ||--|{ ORDER_ITEMS : contains
    ORDERS ||--o| PAYMENTS : paid_via
    USERS ||--o{ USER_ADDRESSES : has

    USERS {
        int id PK
        string first_name
        string last_name
        string email UK
        string password
        string phone
        string provider_id
        string provider_name
    }

    PRODUCTS {
        int id PK
        string name
        string slug UK
        text description
        decimal price
        decimal sale_price
        int stock_quantity
        boolean in_stock
        string status
        boolean featured
    }

    ORDERS {
        int id PK
        int user_id FK
        string status
        string payment_status
        string payment_method
        decimal total
        json shipping_address
        json billing_address
    }

    ORDER_ITEMS {
        int id PK
        int order_id FK
        int product_id FK
        int quantity
        decimal price
        decimal total
    }
```

### Database Choice Rationale

**MySQL** was chosen because:
- The e-commerce domain has clear relational data (users → orders → items → products)
- ACID compliance is critical for financial transactions (orders, payments)
- Rich query support for product filtering, search, and reporting
- Laravel's Eloquent ORM has first-class MySQL support
- MySQL is available as a managed service on all major clouds (AWS RDS, Azure Database, GCP Cloud SQL)

---

## Technology Decision Rationale

| Decision | Choice | Why |
|---|---|---|
| **Application Framework** | Laravel 12 (PHP 8.2) | Mature ecosystem, built-in queue/cache/session abstractions, Eloquent ORM, Blade templating |
| **Database** | MySQL 8.0 | ACID compliance for orders/payments, relational data model, available as RDS |
| **Cache & Sessions** | Redis | In-memory speed, supports pub/sub for inter-service messaging, available as ElastiCache |
| **Message Broker** | Redis (Queues + Pub/Sub) | Already deployed for cache, avoids adding another dependency (vs. RabbitMQ/SQS) |
| **Notification Service** | Node.js + Express | Demonstrates polyglot architecture, lightweight for I/O-bound email sending |
| **Reverse Proxy** | Nginx | Industry standard, static asset serving, load balancing, SSL termination |
| **Containerization** | Docker + Docker Compose | Reproducible deployments, service isolation, mirrors production on local dev |
| **Authentication** | Session-based + OAuth (Google) | Appropriate for server-rendered web app, Socialite for OAuth simplicity |

---

## Scalability Strategy

### Horizontal Scaling

```
                    ┌──────────┐
                    │  Nginx   │
                    │   (LB)   │
                    └────┬─────┘
                         │
              ┌──────────┼──────────┐
              ▼          ▼          ▼
         ┌────────┐ ┌────────┐ ┌────────┐
         │ App #1 │ │ App #2 │ │ App #3 │
         └────────┘ └────────┘ └────────┘
              │          │          │
              └──────────┼──────────┘
                         ▼
                    ┌──────────┐
                    │  Redis   │  ← Shared sessions/cache
                    └──────────┘
```

This works because:
1. **Sessions are in Redis** — not local files. Any app instance can serve any user.
2. **Cache is in Redis** — all instances see the same cached data.
3. **Queues are in Redis** — any worker can process any job.
4. **Database is external** — all instances connect to the same MySQL.

### Scaling Approach per Service

| Service | Scaling | Method |
|---|---|---|
| Web App | Horizontal | Add more `app` containers behind Nginx upstream |
| Queue Worker | Horizontal | Add more `queue-worker` containers (each processes jobs independently) |
| Notification | Horizontal | Add more subscribers (Redis pub/sub fans out to all) |
| MySQL | Vertical + Read Replicas | Scale instance size; add read replicas for read-heavy queries |
| Redis | Vertical + Clustering | Scale instance size; use Redis Cluster for partitioning |

---

## High Availability Design

| Component | HA Strategy |
|---|---|
| **Web App** | Multiple containers behind Nginx load balancer with health checks |
| **Queue Worker** | Multiple worker processes; failed jobs auto-retry (3 attempts) |
| **Notification Service** | Restart policy: `unless-stopped`; health check every 30s |
| **MySQL** | Docker volume persistence; in production: RDS Multi-AZ |
| **Redis** | Docker volume persistence; in production: ElastiCache with replication |
| **Nginx** | Health check on `/health` endpoint; auto-restart on failure |

---

## Extensibility: Adding New Services

To add a new service (e.g., a **Recommendation Engine**):

1. Create a new directory: `services/recommendation/`
2. Add a `Dockerfile` and application code
3. Add the service to `docker-compose.yml`
4. Subscribe to relevant Redis channels or expose a REST API
5. The main app publishes events or calls the API — **no changes needed to existing services**

Example: Adding a recommendation service that listens to purchase events:
```yaml
# In docker-compose.yml
recommendation-service:
  build: ./services/recommendation
  environment:
    - REDIS_HOST=redis
  depends_on:
    - redis
  networks:
    - knuckles-network
```

This demonstrates the **Open/Closed Principle** applied to architecture: open for extension, closed for modification.

---

## Deployment Architecture (Azure AKS)

The production deployment uses **Azure Kubernetes Service (AKS)** for container orchestration and Azure managed services for the data layer.

```mermaid
graph TB
    subgraph "Azure Cloud"
        subgraph "AKS Cluster"
            ING["NGINX Ingress<br/>Controller"]
            subgraph "Namespace: knuckles"
                APP1["App Pod #1"]
                APP2["App Pod #2"]
                QW1["Queue Worker<br/>Pod #1"]
                QW2["Queue Worker<br/>Pod #2"]
                NS["Notification<br/>Service Pod"]
            end
            HPA["HPA<br/>Auto-scaler"]
        end
        ACR["Azure Container<br/>Registry (ACR)"]
        subgraph "Managed Services"
            ADB["Azure Database<br/>for MySQL"]
            ARC["Azure Cache<br/>for Redis"]
        end
        BLOB["Azure Blob<br/>Storage"]
        KV["Azure Key<br/>Vault"]
    end

    ING --> APP1
    ING --> APP2
    APP1 --> ADB
    APP2 --> ADB
    APP1 --> ARC
    APP2 --> ARC
    QW1 --> ADB
    QW2 --> ADB
    QW1 --> ARC
    QW2 --> ARC
    NS --> ARC
    HPA -.-> APP1
    HPA -.-> QW1
    ACR -.-> APP1
    ACR -.-> NS
    APP1 -.-> BLOB
    KV -.-> APP1
```

### Azure Service Mapping

| Component | Azure Service | Purpose |
|---|---|---|
| Container Orchestration | **AKS** | Manages pod lifecycle, scaling, networking |
| Container Images | **Azure Container Registry (ACR)** | Private Docker image registry |
| Database | **Azure Database for MySQL** | Managed MySQL with automatic backups and HA |
| Cache + Message Broker | **Azure Cache for Redis** | Managed Redis for sessions, cache, and queues |
| Object Storage | **Azure Blob Storage** | Product images and static assets |
| Secrets Management | **Azure Key Vault** | Securely stores DB passwords, API keys, etc. |
| DNS + SSL | **Azure DNS + App Gateway** | Domain routing and TLS termination |
| CI/CD | **GitHub Actions** | Build, test, push to ACR, deploy to AKS |

### Kubernetes Manifests

All K8s manifests are in the `k8s/` directory:
- `configmap.yaml` — Non-sensitive environment configuration
- `secrets.yaml` — Sensitive credentials (backed by Azure Key Vault in production)
- `app-deployment.yaml` — Laravel app (2 replicas + HPA auto-scaling 2-10 pods)
- `queue-worker-deployment.yaml` — Queue workers (2 replicas + HPA 1-5 pods)
- `notification-deployment.yaml` — Notification service
- `ingress.yaml` — NGINX Ingress with rate limiting

See `docs/aks-deployment-guide.md` for full deployment instructions.
