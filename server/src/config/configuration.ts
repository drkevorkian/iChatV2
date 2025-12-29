/**
 * Sentinel Chat Platform - Application Configuration
 * 
 * Centralized configuration management with environment variable support.
 * All configuration values are validated using Joi schemas.
 */

export const configuration = () => ({
  app: {
    name: process.env.APP_NAME || 'Sentinel Chat',
    env: process.env.NODE_ENV || 'development',
    port: parseInt(process.env.PORT || '3000', 10),
    url: process.env.APP_URL || 'http://localhost:3000',
  },
  cors: {
    origins: process.env.CORS_ORIGINS || 'https://localhost',
  },
});

