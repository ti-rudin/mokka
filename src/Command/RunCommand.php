<?php

namespace Mokka\Command;


use Mokka\Action\Action;
use Mokka\Action\ActionInterface;
use Mokka\Action\BuyAction;
use Mokka\Action\SellAction;
use Mokka\Calculator\Quantity;
use Mokka\Config\Configurator;
use Mokka\Config\Logger;
use Mokka\Exchange\ExchangeFactory;
use Mokka\Strategy\IndicatorFactory;
use Mokka\Strategy\StrategyCalculator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;


class RunCommand extends Command
{

    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('run')
            ->setDescription('Run Mokka! Run!')
            ->addOption('market', 'm', InputOption::VALUE_OPTIONAL, 'Choose market to run', 'binance')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Seconds for each requests. Default: 60', 60)
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbol for the bot to run', 'BTCUSDT')
            ->addOption('indicator', 'it', InputOption::VALUE_OPTIONAL, 'Which indicator will be applied? (for future development)', 'percent')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'default config file. you can use custom config for each command', 'default')
            ->addOption('test', 't', InputOption::VALUE_OPTIONAL, 'Test mode for botta. If set TRUE botta will not buy and sell any crypto currency', false)

        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Symfony\Component\Debug\Exception\ClassNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //get config first
        try {
            $config = (new Configurator(__DIR__.'/../../config/'.$input->getOption('config').'.yml'))->make();

            //check if Exchange Market provider is available
            $marketConfig = $config->get('markets.'.$input->getOption('market'));
            $market = (new ExchangeFactory($input->getOption('market')))->make([$marketConfig]);

            //set logs (txt db)
            $logFileType =
                $config->get('mokka.default_log_type') == 'date'
                    ? (new \DateTime())->format('Y-m-d')
                    : $input->getOption('symbol');

            $logger = new Logger(__DIR__.'/../../logs/', $logFileType);

            //check the first row in logs
            $this->createActionFile($logger, $input, $output);

            //get indicator
            $indicatorConfig = $config->get('indicators.'.$input->getOption('indicator'));
            $indicator = (new IndicatorFactory($input->getOption('indicator')))
                ->make([$market, $logger, $indicatorConfig]);


            //run strategy calculator
            $strategy = new StrategyCalculator($market, $indicator);

            $strategy->setInterval($input->getOption('interval'));
            $strategy->setSymbol($input->getOption('symbol'));
            $strategy->setMarket($input->getOption('market'));

            $output->writeln('<info>Mokka Started!</info>');
            $table = new Table($output);
            $table->setHeaders(array('Action', 'Previous Price', 'Action Price', 'Symbol', 'Amount', 'Trigger', 'Change', 'Date'));

            while (1) {
                $quantity = new Quantity();
                $action = $strategy->run($logger);

                if ($input->getOption('test') === false) {
                    if ($action->getType() == ActionInterface::TYPE_BUY) {
                        //calculate quantity
                        $maxFund = $config->get('markets.'.$input->getOption('market').'.max_fund');

                        /** @var BuyAction $action */
                        $action->setQuantity(
                            $quantity->buyQuantityCalculator($maxFund, $action->getActionPrice(), $market->getBalance())
                        );

                        $market->buyOrder($action);
                    }


                    if ($action->getType() == ActionInterface::TYPE_SELL) {
                        //get quantity to sell
                        $maxSell = $config->get('markets.'.$input->getOption('market').'.max_sell');

                        $action->setQuantity(
                            $quantity->sellQuantityCalculator($maxSell, $action->getQuantity())
                        );

                        /** @var  SellAction $action */
                        $market->sellOrder($action);
                    }
                }

                //log the action
                if ($action->getType() != ActionInterface::TYPE_IDLE) {
                    $logger->insert()->set($action->toArray())->execute();
                }

                $table->setRows(array(
                    array(
                        $action->getType(),
                        $action->getPreviousPrice(),
                        $action->getActionPrice(),
                        $action->getSymbol(),
                        $action->getQuantity(),
                        $config->get('indicators.percent.default_percent'),
                        round($action->getActionPrice() / $action->getPreviousPrice(), 5),
                        date('Y-m-d H:i:s')
                    ),
                ));

                $table->render();

                sleep($input->getOption('interval'));
            }

            $output->writeln('<question>Mokka stopped!</question>');

        } catch (InvalidConfigurationException $exception) {
            $output->writeln("<error>Invalid Configuration</error>");
        } catch (\Exception $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");
        }
    }

    /**
     * @param Logger $logger
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array
     * @internal param Logger $action
     */
    protected function createActionFile(Logger $logger, InputInterface $input, OutputInterface $output)
    {

        $lastAction = $logger
            ->read()
            ->where('market', '=', $input->getOption('market'))
            ->where('symbol', '=', $input->getOption('symbol'))
            ->sortDesc('lastUpdate')
            ->limit(1)
            ->first();

        if ($lastAction) {
            return;
        }

        $helper = $this->getHelper('question');

        $question1 = new ChoiceQuestion(
            "We need to know your last transaction. Please check the market ({$input->getOption('market')}) and set your last action for {$input->getOption('symbol')}.",
            array('buy', 'sell'),
            0
        );

        $question1->setErrorMessage('Your response is invalid.');
        $chosenActionType = $helper->ask($input, $output, $question1);


        $question2 = new Question("What was the last price for {$input->getOption('symbol')}?");
        $price = $helper->ask($input, $output, $question2);

        if (!$price) {
            $output->writeln("<comment>You need to tell me the last action price. Otherwise I can not move on.</comment>");
            die();
        }

        $actionContent = new Action();
        $actionContent->setType($chosenActionType);
        $actionContent->setSymbol($input->getOption('symbol'));
        $actionContent->setLastUpdate(time());
        $actionContent->setMarket($input->getOption('market'));
        $actionContent->setPreviousPrice($price);
        $actionContent->setActionPrice($price);

        $output->writeln("<info>OK. I know what to do  now ;) .</info>");
        $logger->insert()->set($actionContent->toArray())->execute();

        return (array) $actionContent;
    }


}