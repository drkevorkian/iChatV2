/**
 * Sentinel Chat Platform - Health Controller
 * 
 * Handles health check requests for monitoring and load balancing.
 */

import { Controller, Get } from '@nestjs/common';
import { HealthService } from './health.service';

@Controller('health')
export class HealthController {
  constructor(private readonly healthService: HealthService) {}

  @Get()
  check() {
    return this.healthService.check();
  }

  @Get('ready')
  readiness() {
    return this.healthService.readiness();
  }

  @Get('live')
  liveness() {
    return this.healthService.liveness();
  }
}

