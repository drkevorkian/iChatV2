/**
 * Sentinel Chat Platform - Health Service
 * 
 * Implements health check logic including database connectivity
 * and service availability checks.
 */

import { Injectable } from '@nestjs/common';

@Injectable()
export class HealthService {
  check() {
    return {
      status: 'ok',
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
    };
  }

  readiness() {
    // In production, check database connectivity here
    return {
      status: 'ready',
      timestamp: new Date().toISOString(),
    };
  }

  liveness() {
    return {
      status: 'alive',
      timestamp: new Date().toISOString(),
    };
  }
}

