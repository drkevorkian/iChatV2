/**
 * Sentinel Chat Platform - Messaging Controller
 * 
 * REST endpoints for message operations.
 */

import { Controller, Get, Post, Body, Param, UseGuards } from '@nestjs/common';
import { MessagingService } from './messaging.service';

@Controller('messaging')
export class MessagingController {
  constructor(private readonly messagingService: MessagingService) {}

  @Get('rooms/:roomId/messages')
  getRoomMessages(@Param('roomId') roomId: string) {
    return this.messagingService.getRoomMessages(roomId);
  }

  @Post('rooms/:roomId/messages')
  sendMessage(@Param('roomId') roomId: string, @Body() messageDto: any) {
    return this.messagingService.sendMessage(roomId, messageDto);
  }
}

