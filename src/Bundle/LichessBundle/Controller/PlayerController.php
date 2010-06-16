<?php

namespace Bundle\LichessBundle\Controller;

use Symfony\Framework\WebBundle\Controller;
use Bundle\LichessBundle\Chess\Analyser;
use Bundle\LichessBundle\Chess\Manipulator;
use Bundle\LichessBundle\Socket;
use Bundle\LichessBundle\Stack;
use Bundle\LichessBundle\Ai\Crafty;
use Symfony\Components\HttpKernel\Exception\NotFoundHttpException;

class PlayerController extends Controller
{
    public function moveAction($hash)
    {
        $player = $this->findPlayer($hash);
        $opponent = $player->getOpponent();
        $game = $player->getGame();
        if(!$player->isMyTurn()) {
            throw new NotFoundHttpException('Not my turn');
        }
        $move = $this->getRequest()->get('from').' '.$this->getRequest()->get('to');
        $stack = new Stack();
        $manipulator = new Manipulator($game->getBoard(), $stack);
        try {
            $opponentPossibleMoves = $manipulator->play($move, $this->getRequest()->get('options', array()));
        }
        catch(Exception $e) {
            throw new NotFoundHttpException($e->getMessage());
        }
        $data = array(
            'time' => time(),
            'possible_moves' => null,
            'events' => $stack->getEvents()
        );
        if($game->getIsFinished()) {
            $data['events'][] = array(
                'type' => 'mate',
                'table_url'  => $this->generateUrl('lichess_table', array('hash' => $player->getFullHash()))
            );
        }
        $response = $this->createResponse(json_encode($data));
        $response->headers->set('Content-Type', 'application/json');
        
        if($opponent->getIsAi()) {
            if(empty($opponentPossibleMoves)) {
                $this->container->getLichessPersistenceService()->save($game);
            }
            else {
                $ai = new Crafty($opponent, array('level' => $opponent->getAiLevel()));
                $stack->reset();
                $possibleMoves = $manipulator->play($ai->move());
                $this->container->getLichessPersistenceService()->save($game);

                $socket = new Socket($player, $this->container['kernel.root_dir'].'/cache/socket');
                $data = array(
                    'possible_moves' => $possibleMoves,
                    'events' => $stack->getEvents()
                );
                if($game->getIsFinished()) {
                    $data['events'][] = array(
                        'type' => 'mate',
                        'table_url'  => $this->generateUrl('lichess_table', array('hash' => $player->getFullHash()))
                    );
                }
                $socket->write($data);
            }
        }
        else {
            $this->container->getLichessPersistenceService()->save($game);
            $data['possible_moves'] = $opponentPossibleMoves;
            $socket = new Socket($opponent, $this->container['kernel.root_dir'].'/cache/socket');
            $socket->write($data);
        }

        return $response;
    }

    public function showAction($hash)
    {
        $player = $this->findPlayer($hash);
        $game = $player->getGame();

        $analyser = new Analyser($game->getBoard());
        $isKingAttacked = $analyser->isKingAttacked($game->getTurnPlayer());
        if($isKingAttacked) {
            $checkSquareKey = $game->getTurnPlayer()->getKing()->getSquareKey();
        }
        else {
            $checkSquareKey = null;
        }
        $socket = new Socket($player, $this->container['kernel.root_dir'].'/cache/socket');
        $socket->write(array());

        return $this->render('LichessBundle:Player:show', array(
            'player' => $player,
            'checkSquareKey' => $checkSquareKey,
            'possibleMoves' => ($player->isMyTurn() && !$game->getIsFinished()) ? $analyser->getPlayerPossibleMoves($player, $isKingAttacked) : null
        ));
    }

    public function inviteAiAction($hash)
    {
        $player = $this->findPlayer($hash);
        $opponent = $player->getOpponent();
        $game = $player->getGame();

        if($game->getIsStarted()) {
            throw new NotFoundHttpException('Game already started');
        }

        $opponent->setIsAi(true);
        $opponent->setAiLevel(1);
        $game->setIsStarted(true);

        if($player->isBlack()) {
            $ai = new Crafty($opponent);
            $stack = new Stack();
            $manipulator = new Manipulator($game->getBoard(), $stack);
            $possibleMoves = $manipulator->play($ai->move());
            $this->container->getLichessPersistenceService()->save($game);
        }
        else {
            $this->container->getLichessPersistenceService()->save($game);
        }

        return $this->redirect($this->generateUrl('lichess_player', array(
            'hash' => $player->getFullHash()
        )));
    }

    public function resignAction($hash)
    {
        $player = $this->findPlayer($hash);
        $game = $player->getGame();
        $opponent = $player->getOpponent();

        $game->setIsFinished(true);
        $opponent->setIsWinner(true);
        $this->container->getLichessPersistenceService()->save($game);
        
        $data = array(
            'time' => time(),
            'possible_moves' => null,
            'events' => array(array(
                'type' => 'resign',
                'table_url'  => $this->generateUrl('lichess_table', array('hash' => $player->getFullHash()))
            ))
        );
        if(!$opponent->getIsAi()) {
            $socket = new Socket($opponent, $this->container['kernel.root_dir'].'/cache/socket');
            $socket->write($data);
        }

        $response = $this->createResponse(json_encode($data));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    public function aiLevelAction($hash)
    {
        $player = $this->findPlayer($hash);
        $opponent = $player->getOpponent();
        $level = min(8, max(1, (int)$this->getRequest()->get('level')));
        $opponent->setAiLevel($level);
        $this->container->getLichessPersistenceService()->save($player->getGame());
        return $this->createResponse('done');
    }

    public function tableAction($hash)
    {
        $player = $this->findPlayer($hash);
        $template = $player->getGame()->getIsFinished() ? 'tableEnd' : 'table';
        return $this->render('LichessBundle:Game:'.$template, array(
            'player' => $player
        ));
    }

    /**
     * Get the player for this hash 
     * 
     * @param string $hash 
     * @return Player
     */
    protected function findPlayer($hash)
    {
        $gameHash = substr($hash, 0, 6);
        $playerHash = substr($hash, 6, 10);

        $game = $this->container->getLichessPersistenceService()->find($gameHash);
        if(!$game) {
            throw new NotFoundHttpException('Can\'t find game '.$gameHash);
        } 

        $player = $game->getPlayerByHash($playerHash);
        if(!$player) {
            throw new NotFoundHttpException('Can\'t find player '.$playerHash);
        } 

        return $player;
    }
}
