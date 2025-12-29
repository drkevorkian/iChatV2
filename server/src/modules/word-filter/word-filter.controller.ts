/**
 * Sentinel Chat Platform - Word Filter Controller
 * 
 * REST endpoints for word filtering operations.
 */

import { Controller, Post, Body } from '@nestjs/common';
import { WordFilterService } from './word-filter.service';

@Controller('word-filter')
export class WordFilterController {
  constructor(private readonly wordFilterService: WordFilterService) {}

  @Post('filter')
  filterMessage(@Body() messageDto: any) {
    return this.wordFilterService.filterMessage(messageDto.text);
  }
}

