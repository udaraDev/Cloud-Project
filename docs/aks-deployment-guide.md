# KnucklesProducts — Azure AKS Deployment Guide

## Docker Compose → AKS Mapping

Your current architecture maps 1:1 to Kubernetes. Here's the translation:

| Docker Compose | Kubernetes (AKS) | Azure Managed Service |
|---|---|---|
| `nginx` container | Ingress Controller (NGINX) | Azure Application Gateway (optional) |
| `app` container | Deployment + Service | — |
| `queue-worker` container | Deployment (no Service needed) | — |
| `notification-service` container | Deployment + Service | — |
| `mysql` container | ❌ Remove from K8s | **Azure Database for MySQL** |
| `redis` container | ❌ Remove from K8s | **Azure Cache for Redis** |
| Docker volumes | PersistentVolumeClaims | Azure Managed Disks |
| `docker-compose.yml` | K8s manifests (YAML) | — |
| `.env` file | ConfigMap + Secret | Azure Key Vault |
| Health checks | Liveness + Readiness Probes | — |
| Container images | ACR (Azure Container Registry) | **ACR** |

## Key Principle

> In AKS, **stateless services** (app, worker, notification) run as Kubernetes pods.  
> **Stateful services** (MySQL, Redis) become **Azure managed services** — you don't run databases in containers in production.

---

## Architecture on AKS

```
┌─────────────────────────────────────────────────────────────────┐
│                        AZURE CLOUD                               │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    AKS CLUSTER                            │   │
│  │                                                           │   │
│  │  ┌─────────────┐    ┌──────────────────────────────────┐ │   │
│  │  │   NGINX     │    │         NAMESPACE: production     │ │   │
│  │  │  Ingress    │───▶│                                   │ │   │
│  │  │ Controller  │    │  ┌─────────┐  ┌─────────┐       │ │   │
│  │  └─────────────┘    │  │ App Pod │  │ App Pod │       │ │   │
│  │                      │  │  #1     │  │  #2     │ ←HPA │ │   │
│  │                      │  └────┬────┘  └────┬────┘       │ │   │
│  │                      │       │            │             │ │   │
│  │                      │  ┌────┴────────────┴────┐       │ │   │
│  │                      │  │    Queue Workers      │       │ │   │
│  │                      │  │    (2-5 replicas)     │ ←HPA │ │   │
│  │                      │  └──────────┬───────────┘       │ │   │
│  │                      │             │                    │ │   │
│  │                      │  ┌──────────┴───────────┐       │ │   │
│  │                      │  │ Notification Service  │       │ │   │
│  │                      │  │   (Node.js pods)      │       │ │   │
│  │                      │  └──────────────────────┘       │ │   │
│  │                      └──────────────────────────────────┘ │   │
│  └──────────────────────────────────────────────────────────┘   │
│                          │                │                      │
│                    ┌─────▼──────┐  ┌──────▼────────┐            │
│                    │ Azure DB   │  │ Azure Cache   │            │
│                    │ for MySQL  │  │ for Redis     │            │
│                    │ (Managed)  │  │ (Managed)     │            │
│                    └────────────┘  └───────────────┘            │
│                                                                  │
│  ┌────────────────┐  ┌────────────────┐  ┌─────────────────┐   │
│  │ Azure Blob     │  │ Azure          │  │ Azure Key      │   │
│  │ Storage        │  │ Container      │  │ Vault          │   │
│  │ (Static Files) │  │ Registry (ACR) │  │ (Secrets)      │   │
│  └────────────────┘  └────────────────┘  └─────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Step-by-Step AKS Deployment

### Prerequisites
```bash
# Install Azure CLI and kubectl
az login
az aks install-cli

# Set variables
RESOURCE_GROUP="knuckles-rg"
CLUSTER_NAME="knuckles-aks"
ACR_NAME="knucklesacr"
LOCATION="southeastasia"
```

### Step 1: Create Azure Resources
```bash
# Create resource group
az group create --name $RESOURCE_GROUP --location $LOCATION

# Create Azure Container Registry
az acr create --resource-group $RESOURCE_GROUP --name $ACR_NAME --sku Basic

# Create AKS cluster (attached to ACR)
az aks create \
  --resource-group $RESOURCE_GROUP \
  --name $CLUSTER_NAME \
  --node-count 2 \
  --node-vm-size Standard_B2s \
  --attach-acr $ACR_NAME \
  --enable-managed-identity \
  --generate-ssh-keys

# Get kubectl credentials
az aks get-credentials --resource-group $RESOURCE_GROUP --name $CLUSTER_NAME

# Create Azure Database for MySQL
az mysql flexible-server create \
  --resource-group $RESOURCE_GROUP \
  --name knuckles-mysql \
  --admin-user knuckles \
  --admin-password <YOUR_DB_PASSWORD> \
  --sku-name Standard_B1ms \
  --tier Burstable \
  --database-name knucklesproducts

# Create Azure Cache for Redis
az redis create \
  --resource-group $RESOURCE_GROUP \
  --name knuckles-redis \
  --location $LOCATION \
  --sku Basic \
  --vm-size c0
```

### Step 2: Build and Push Docker Images to ACR
```bash
# Login to ACR
az acr login --name $ACR_NAME

# Build and push Laravel app image
docker build -t $ACR_NAME.azurecr.io/knuckles-app:latest -f docker/app/Dockerfile .
docker push $ACR_NAME.azurecr.io/knuckles-app:latest

# Build and push Notification service image
docker build -t $ACR_NAME.azurecr.io/knuckles-notification:latest -f services/notification/Dockerfile services/notification/
docker push $ACR_NAME.azurecr.io/knuckles-notification:latest
```

### Step 3: Deploy to AKS
```bash
# Create namespace
kubectl create namespace knuckles

# Apply secrets and configmap
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/secrets.yaml

# Deploy all services
kubectl apply -f k8s/app-deployment.yaml
kubectl apply -f k8s/queue-worker-deployment.yaml
kubectl apply -f k8s/notification-deployment.yaml
kubectl apply -f k8s/ingress.yaml

# Verify
kubectl get pods -n knuckles
kubectl get services -n knuckles
```

### Step 4: Run Database Migrations
```bash
# Get a pod name
POD=$(kubectl get pods -n knuckles -l app=knuckles-app -o jsonpath='{.items[0].metadata.name}')

# Run migrations
kubectl exec -n knuckles $POD -- php artisan migrate --force

# Seed database
kubectl exec -n knuckles $POD -- php artisan db:seed --force
```

---

## Scaling with AKS

### Manual Scaling
```bash
# Scale web app to 3 replicas
kubectl scale deployment knuckles-app -n knuckles --replicas=3

# Scale queue workers to 5
kubectl scale deployment knuckles-queue-worker -n knuckles --replicas=5
```

### Auto-Scaling (HPA)
The HPA manifests are included in the K8s files. They automatically scale based on CPU:
- **App**: 2-10 pods (scales at 70% CPU)
- **Queue Worker**: 1-5 pods (scales at 80% CPU)

```bash
# Check HPA status
kubectl get hpa -n knuckles
```

---

## Monitoring and Observability
```bash
# View pod logs
kubectl logs -n knuckles -l app=knuckles-app --tail=100

# View queue worker logs
kubectl logs -n knuckles -l app=knuckles-queue-worker --tail=100

# View notification service logs
kubectl logs -n knuckles -l app=knuckles-notification --tail=100

# Port-forward to notification service health check
kubectl port-forward -n knuckles svc/knuckles-notification 3001:3001
# Then visit: http://localhost:3001/health
```
