/**
 * Sentinel Chat Platform - Root Application Module
 * 
 * Configures all modules, services, and dependencies for the NestJS application.
 * This is the root module that imports all feature modules.
 */

import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ThrottlerModule } from '@nestjs/throttler';
import { HealthModule } from './modules/health/health.module';
import { MessagingModule } from './modules/messaging/messaging.module';
import { RoomsModule } from './modules/rooms/rooms.module';
import { KeyEscrowModule } from './modules/key-escrow/key-escrow.module';
import { WordFilterModule } from './modules/word-filter/word-filter.module';
import { configuration } from './config/configuration';
import { databaseConfig } from './config/database.config';
import { redisConfig } from './config/redis.config';
import { securityConfig } from './config/security.config';

@Module({
  imports: [
    // Configuration module with validation
    ConfigModule.forRoot({
      isGlobal: true,
      load: [configuration, databaseConfig, redisConfig, securityConfig],
      validationSchema: require('./config/validation.schema'),
    }),
    
    // Rate limiting - prevents abuse
    ThrottlerModule.forRoot([
      {
        ttl: 60000, // 1 minute
        limit: 100, // 100 requests per minute
      },
    ]),
    
    // Feature modules
    HealthModule,
    MessagingModule,
    RoomsModule,
    KeyEscrowModule,
    WordFilterModule,
  ],
})
export class AppModule {}

