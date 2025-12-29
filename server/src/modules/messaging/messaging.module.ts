/**
 * Sentinel Chat Platform - Messaging Module
 * 
 * Handles message operations including sending, receiving, and queuing.
 */

import { Module } from '@nestjs/common';
import { MessagingController } from './messaging.controller';
import { MessagingService } from './messaging.service';

@Module({
  controllers: [MessagingController],
  providers: [MessagingService],
})
export class MessagingModule {}

