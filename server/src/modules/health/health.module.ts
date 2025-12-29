/**
 * Sentinel Chat Platform - Health Module
 * 
 * Provides health check endpoints for monitoring and load balancers.
 */

import { Module } from '@nestjs/common';
import { HealthController } from './health.controller';
import { HealthService } from './health.service';

@Module({
  controllers: [HealthController],
  providers: [HealthService],
})
export class HealthModule {}

