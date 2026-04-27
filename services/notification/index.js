/**
 * ============================================================
 * KnucklesProducts — Notification Microservice
 * ============================================================
 * 
 * A lightweight Node.js microservice that:
 *   1. Subscribes to Redis pub/sub channels for notification events
 *   2. Sends email notifications (order confirmations, welcome emails)
 *   3. Exposes a REST API for direct notification triggers
 *   4. Has its own health check endpoint
 * 
 * Communication Pattern:
 *   Laravel App → Redis (PUBLISH) → This Service (SUBSCRIBE) → SMTP
 * 
 * This demonstrates:
 *   - Asynchronous inter-service communication via message broker
 *   - Polyglot architecture (PHP + Node.js)
 *   - Independent deployability
 *   - Single responsibility principle
 * ============================================================
 */

const express = require('express');
const Redis = require('ioredis');
const nodemailer = require('nodemailer');

const app = express();
app.use(express.json());

// ─── Configuration ─────────────────────────────────────────
const config = {
    port: process.env.PORT || 3001,
    redis: {
        host: process.env.REDIS_HOST || 'redis',
        port: parseInt(process.env.REDIS_PORT || '6379'),
    },
    smtp: {
        host: process.env.SMTP_HOST || 'localhost',
        port: parseInt(process.env.SMTP_PORT || '1025'),
        auth: process.env.SMTP_USER ? {
            user: process.env.SMTP_USER,
            pass: process.env.SMTP_PASS,
        } : undefined,
    },
    mailFrom: process.env.MAIL_FROM || 'noreply@knucklesproducts.com',
};

// ─── Metrics (simple in-memory tracking) ───────────────────
const metrics = {
    startedAt: new Date().toISOString(),
    emailsSent: 0,
    emailsFailed: 0,
    eventsReceived: 0,
    lastEventAt: null,
};

// ─── Redis Subscriber ──────────────────────────────────────
const subscriber = new Redis(config.redis);
const redis = new Redis(config.redis);

subscriber.on('connect', () => {
    console.log('✅ Connected to Redis broker');
});

subscriber.on('error', (err) => {
    console.error('❌ Redis connection error:', err.message);
});

// ─── Email Transporter ─────────────────────────────────────
const transporter = nodemailer.createTransport(config.smtp);

// ─── Email Templates ───────────────────────────────────────
function buildOrderConfirmationEmail(data) {
    const items = data.items || [];
    const itemRows = items.map(item =>
        `<tr>
            <td style="padding: 10px; border-bottom: 1px solid #eee;">${item.name}</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: center;">${item.quantity}</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee; text-align: right;">LKR ${parseFloat(item.total).toFixed(2)}</td>
        </tr>`
    ).join('');

    return {
        subject: `Order Confirmation #${data.order_id} — KnucklesProducts`,
        html: `
        <div style="font-family: 'Segoe UI', Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8f9fa;">
            <div style="background: linear-gradient(135deg, #2d5016 0%, #4a7c28 100%); padding: 30px; text-align: center;">
                <h1 style="color: white; margin: 0; font-size: 24px;">🎉 Order Confirmed!</h1>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0;">Thank you for your purchase</p>
            </div>
            <div style="padding: 30px; background: white;">
                <p>Hi <strong>${data.customer_name || 'Customer'}</strong>,</p>
                <p>Your order <strong>#${data.order_id}</strong> has been confirmed and is being processed.</p>
                
                <h3 style="border-bottom: 2px solid #4a7c28; padding-bottom: 8px;">Order Summary</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f1f5f0;">
                            <th style="padding: 10px; text-align: left;">Product</th>
                            <th style="padding: 10px; text-align: center;">Qty</th>
                            <th style="padding: 10px; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>${itemRows}</tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="padding: 12px; font-weight: bold; text-align: right;">Total:</td>
                            <td style="padding: 12px; font-weight: bold; text-align: right; color: #4a7c28;">LKR ${parseFloat(data.total).toFixed(2)}</td>
                        </tr>
                    </tfoot>
                </table>

                <div style="margin-top: 20px; padding: 15px; background: #f1f5f0; border-radius: 8px;">
                    <strong>Payment Method:</strong> ${data.payment_method || 'N/A'}<br>
                    <strong>Status:</strong> <span style="color: #4a7c28;">Pending</span>
                </div>
            </div>
            <div style="padding: 20px; text-align: center; color: #666; font-size: 12px;">
                <p>KnucklesProducts — Handcrafted from the Knuckles Mountains 🏔️</p>
            </div>
        </div>`
    };
}

function buildWelcomeEmail(data) {
    return {
        subject: 'Welcome to KnucklesProducts! 🌿',
        html: `
        <div style="font-family: 'Segoe UI', Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #2d5016 0%, #4a7c28 100%); padding: 30px; text-align: center;">
                <h1 style="color: white; margin: 0;">Welcome! 🎉</h1>
            </div>
            <div style="padding: 30px; background: white;">
                <p>Hi <strong>${data.name || 'there'}</strong>,</p>
                <p>Welcome to KnucklesProducts! We're thrilled to have you join our community.</p>
                <p>Explore our handcrafted products from the beautiful Knuckles mountain range of Sri Lanka.</p>
                <a href="${data.app_url || '#'}" style="display: inline-block; background: #4a7c28; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin-top: 15px;">Start Shopping →</a>
            </div>
        </div>`
    };
}

// ─── Send Email Function ───────────────────────────────────
async function sendEmail(to, template) {
    try {
        const info = await transporter.sendMail({
            from: `"KnucklesProducts" <${config.mailFrom}>`,
            to: to,
            subject: template.subject,
            html: template.html,
        });
        metrics.emailsSent++;
        console.log(`📧 Email sent to ${to} | MessageId: ${info.messageId}`);
        return { success: true, messageId: info.messageId };
    } catch (error) {
        metrics.emailsFailed++;
        console.error(`❌ Failed to send email to ${to}:`, error.message);
        return { success: false, error: error.message };
    }
}

// ─── Redis Event Handlers ──────────────────────────────────
subscriber.subscribe('order:confirmed', 'user:registered', (err, count) => {
    if (err) {
        console.error('❌ Failed to subscribe to channels:', err.message);
        return;
    }
    console.log(`📡 Subscribed to ${count} notification channels`);
});

subscriber.on('message', async (channel, message) => {
    metrics.eventsReceived++;
    metrics.lastEventAt = new Date().toISOString();
    console.log(`📨 Event received on [${channel}]`);

    try {
        const data = JSON.parse(message);

        switch (channel) {
            case 'order:confirmed':
                const orderEmail = buildOrderConfirmationEmail(data);
                await sendEmail(data.email, orderEmail);
                break;

            case 'user:registered':
                const welcomeEmail = buildWelcomeEmail(data);
                await sendEmail(data.email, welcomeEmail);
                break;

            default:
                console.log(`⚠️ Unknown channel: ${channel}`);
        }
    } catch (error) {
        console.error(`❌ Error processing event on [${channel}]:`, error.message);
    }
});

// ─── REST API Endpoints ────────────────────────────────────

// Health check (used by Docker and load balancer)
app.get('/health', (req, res) => {
    const redisConnected = subscriber.status === 'ready';
    res.status(redisConnected ? 200 : 503).json({
        service: 'notification-service',
        status: redisConnected ? 'healthy' : 'degraded',
        redis: redisConnected ? 'connected' : 'disconnected',
        uptime: process.uptime(),
        metrics: metrics,
    });
});

// Direct notification trigger (synchronous API call from other services)
app.post('/api/notify', async (req, res) => {
    const { type, to, data } = req.body;

    if (!type || !to) {
        return res.status(400).json({ error: 'Missing required fields: type, to' });
    }

    let template;
    switch (type) {
        case 'order_confirmation':
            template = buildOrderConfirmationEmail(data || {});
            break;
        case 'welcome':
            template = buildWelcomeEmail(data || {});
            break;
        default:
            return res.status(400).json({ error: `Unknown notification type: ${type}` });
    }

    const result = await sendEmail(to, template);
    res.status(result.success ? 200 : 500).json(result);
});

// Service info
app.get('/', (req, res) => {
    res.json({
        service: 'KnucklesProducts Notification Service',
        version: '1.0.0',
        channels: ['order:confirmed', 'user:registered'],
        endpoints: {
            health: 'GET /health',
            notify: 'POST /api/notify',
        },
    });
});

// ─── Start Server ──────────────────────────────────────────
app.listen(config.port, () => {
    console.log(`
    ╔══════════════════════════════════════════════════╗
    ║   KnucklesProducts Notification Service          ║
    ║   Running on port ${config.port}                         ║
    ║   Redis: ${config.redis.host}:${config.redis.port}                       ║
    ╚══════════════════════════════════════════════════╝
    `);
});
