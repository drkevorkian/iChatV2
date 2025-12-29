/**
 * Sentinel Chat Platform - Key Escrow Controller
 * 
 * REST endpoints for key escrow operations.
 */

import { Controller, Get, Post, Body, Param } from '@nestjs/common';
import { KeyEscrowService } from './key-escrow.service';

@Controller('key-escrow')
export class KeyEscrowController {
  constructor(private readonly keyEscrowService: KeyEscrowService) {}

  @Get('requests')
  listRequests() {
    return this.keyEscrowService.listRequests();
  }

  @Post('requests')
  createRequest(@Body() requestDto: any) {
    return this.keyEscrowService.createRequest(requestDto);
  }

  @Post('requests/:requestId/approve')
  approveRequest(@Param('requestId') requestId: string) {
    return this.keyEscrowService.approveRequest(requestId);
  }
}

