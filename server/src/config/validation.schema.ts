/**
 * Sentinel Chat Platform - Configuration Validation Schema
 * 
 * Uses Joi to validate environment variables and configuration.
 * Ensures all required values are present and properly formatted.
 */

import * as Joi from 'joi';

export default Joi.object({
  NODE_ENV: Joi.string()
    .valid('development', 'production', 'test')
    .default('development'),
  PORT: Joi.number().default(3000),
  APP_URL: Joi.string().uri().default('http://localhost:3000'),
  CORS_ORIGINS: Joi.string().default('https://localhost'),
  
  // Database
  DB_HOST: Joi.string().default('localhost'),
  DB_PORT: Joi.number().default(5432),
  DB_USER: Joi.string().required(),
  DB_PASSWORD: Joi.string().required(),
  DB_NAME: Joi.string().required(),
  DB_SSL: Joi.string().valid('true', 'false').default('false'),
  
  // Redis
  REDIS_HOST: Joi.string().default('localhost'),
  REDIS_PORT: Joi.number().default(6379),
  REDIS_PASSWORD: Joi.string().optional(),
  REDIS_DB: Joi.number().default(0),
  REDIS_TTL: Joi.number().default(3600),
  
  // Security
  JWT_SECRET: Joi.string().min(32).required(),
  JWT_EXPIRES_IN: Joi.string().default('24h'),
  ENCRYPTION_KEY: Joi.string().min(32).required(),
  API_SHARED_SECRET: Joi.string().min(16).required(),
  BCRYPT_ROUNDS: Joi.number().min(10).max(15).default(10),
});

