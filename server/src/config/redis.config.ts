/**
 * Sentinel Chat Platform - Redis Configuration
 * 
 * Redis configuration for caching and session management.
 */

export const redisConfig = () => ({
  redis: {
    host: process.env.REDIS_HOST || 'localhost',
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD || undefined,
    db: parseInt(process.env.REDIS_DB || '0', 10),
    ttl: parseInt(process.env.REDIS_TTL || '3600', 10), // Default 1 hour
  },
});

