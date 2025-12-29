/**
 * Sentinel Chat Platform - Rooms Controller
 * 
 * REST endpoints for room operations.
 */

import { Controller, Get, Post, Body, Param } from '@nestjs/common';
import { RoomsService } from './rooms.service';

@Controller('rooms')
export class RoomsController {
  constructor(private readonly roomsService: RoomsService) {}

  @Get()
  listRooms() {
    return this.roomsService.listRooms();
  }

  @Get(':roomId')
  getRoom(@Param('roomId') roomId: string) {
    return this.roomsService.getRoom(roomId);
  }

  @Post()
  createRoom(@Body() roomDto: any) {
    return this.roomsService.createRoom(roomDto);
  }
}

