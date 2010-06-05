<?php

namespace Bundle\LichessBundle\Entities\Piece;
use Bundle\LichessBundle\Entities\Piece;

class Knight extends Piece
{
    public function getClass()
    {
        return 'Knight';
    }
    
    public function getBasicTargetSquares()
    {
        $mySquare = $this->getSquare();

        return $this->cannibalismFilter(array(
            $mySquare->getSquareByRelativePos(-1, -2),
            $mySquare->getSquareByRelativePos(1, -2),
            $mySquare->getSquareByRelativePos(2, -1),
            $mySquare->getSquareByRelativePos(2, 1),
            $mySquare->getSquareByRelativePos(1, 2),
            $mySquare->getSquareByRelativePos(-1, 2),
            $mySquare->getSquareByRelativePos(-2, 1),
            $mySquare->getSquareByRelativePos(-2, -1)
        ));
    }
}
