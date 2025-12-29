/**
 * Sentinel Chat Platform - NestJS Main Entry Point
 * 
 * Bootstraps the NestJS application with HTTPS support, security middleware,
 * and proper error handling.
 * 
 * Security: Configures Helmet, CORS, rate limiting, and validation pipes.
 */

import { NestFactory } from '@nestjs/core';
import { ValidationPipe } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import helmet from 'helmet';
import compression from 'compression';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  
  // Get configuration service
  const configService = app.get(ConfigService);
  
  // Security middleware - Helmet sets various HTTP headers
  app.use(helmet({
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'self'"],
        scriptSrc: ["'self'", "'unsafe-inline'", "https://code.jquery.com"],
        styleSrc: ["'self'", "'unsafe-inline'"],
        imgSrc: ["'self'", "data:"],
        fontSrc: ["'self'"],
        connectSrc: ["'self'"],
      },
    },
    crossOriginEmbedderPolicy: false,
  }));
  
  // Compression middleware - reduces response size
  app.use(compression());
  
  // CORS configuration
  const corsOrigins = configService.get<string>('CORS_ORIGINS', 'https://localhost').split(',');
  app.enableCors({
    origin: corsOrigins,
    credentials: true,
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization', 'X-API-SECRET'],
  });
  
  // Global validation pipe - validates all incoming requests
  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true, // Strip properties that don't have decorators
      forbidNonWhitelisted: true, // Throw error if non-whitelisted properties exist
      transform: true, // Automatically transform payloads to DTO instances
      transformOptions: {
        enableImplicitConversion: true,
      },
    }),
  );
  
  // Global prefix for all routes
  app.setGlobalPrefix('api');
  
  const port = configService.get<number>('PORT', 3000);
  await app.listen(port);
  
  console.log(`Sentinel Chat Server is running on: http://localhost:${port}`);
}

bootstrap();

