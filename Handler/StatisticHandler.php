<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Statistic\Handler;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\Propel;
use Statistic\Query\OrderByHoursQuery;
use Statistic\Query\StatsOrderQuery;
use Statistic\Statistic;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Model\CountryQuery;
use Thelia\Model\CouponQuery;
use Thelia\Model\Map\CouponTableMap;
use Thelia\Model\Map\ModuleI18nTableMap;
use Thelia\Model\Map\ModuleTableMap;
use Thelia\Model\Map\OrderAddressTableMap;
use Thelia\Model\Map\OrderCouponTableMap;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderProductTaxTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\ProductQuery;
use Thelia\TaxEngine\Calculator;
use Thelia\Tools\MoneyFormat;

/**
 * Class StatisticHandler
 * @package Statistic\Handler
 * @author David Gros <dgros@openstudio.fr>
 */
class StatisticHandler
{
    const START_DAY_FORMAT = 'Y-m-d 00:00:00';
    const END_DAY_FORMAT = 'Y-m-d 23:59:59';

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @return array
     * @throws \Exception
     */
    public function averageCart(\DateTime $startDate, \DateTime $endDate)
    {
        $po = $this->getMonthlySaleStats($startDate, $endDate);
        $order = $this->getMonthlyOrdersStats($startDate, $endDate);

        $result = array();
        $result['stats'] = array();
        $result['label'] = array();
        $i = 0;
        foreach ($po as $date => $gold) {
            $key = explode('-', $date);
            array_push($result['stats'],array($i, $gold && isset($order[$date]) ? $gold / $order[$date] : 0));
            array_push($result['label'], array($i,$key[2] . '/' . $key[1]));
            $i++;
        }

        return $result;
    }

    /**
     * @param Request $request
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @return array
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function bestSales(Request $request, \DateTime $startDate, \DateTime $endDate)
    {
        $query = $this->bestSalesQuery($startDate, $endDate);
        $result = $query->find()->toArray();

        $calc = new Calculator();
        $countries = array();

        foreach ($result as &$pse) {
            $country = isset($countries[$pse['country']])
                ? $countries[$pse['country']]
                : $countries[$pse['country']] = CountryQuery::create()->findOneById($pse['country']);

            $product = ProductQuery::create()
                ->useProductSaleElementsQuery()
                ->filterById($pse['product_sale_elements_id'])
                ->endUse()
                ->findOne();

            if (null === $product) {
                $product = ProductQuery::create()
                    ->findOneByRef($pse['product_ref']);
            }

            if (null !== $product) {
                $calc->load($product, $country);
                $totalHt = $pse['total_ht'];

                $pse['total_ht'] = MoneyFormat::getInstance($request)->formatByCurrency($totalHt);
                $pse['total_ttc'] = MoneyFormat::getInstance($request)->formatByCurrency($calc->getTaxedPrice($totalHt));
            }
        }

        return $result;
    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @return array
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function discountCode(\DateTime $startDate, \DateTime $endDate)
    {
        $query = $this->discountCodeQuery($startDate, $endDate);

        $result = $query->find()->toArray();

        return $result;

    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param $local
     * @return array
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function meansTransport(\DateTime $startDate, \DateTime $endDate, $local)
    {
        $query = $this->meansTransportQuery($startDate, $endDate, $local);

        $result = $query->find()->toArray();

        return $result;
    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param $local
     * @return array
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function meansPayment(\DateTime $startDate, \DateTime $endDate, $local)
    {
        $query = $this->meansPaymentQuery($startDate, $endDate, $local);

        $result = $query->find()->toArray();

        return $result;
    }

    /**
     * @param $year
     * @return array
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function turnover($year)
    {
        $query = $this->turnoverQuery($year);

        $result = $query->find()->toArray('date');

        return $result;
    }

    // -----------------
    // Query methods

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @return array
     * @throws \Exception
     */
    public function getMonthlySaleStats(\DateTime $startDate, \DateTime $endDate)
    {
        $result = array();
        /** @var \DateTime $date */
        for ($date = clone($startDate); $date <= $endDate; $date->add(new \DateInterval('P1D'))) {
            $result[$date->format('Y-m-d')] = StatsOrderQuery::getSaleStats(
                $date->setTime(0, 0),
                $date->setTime(23, 59, 59),
                false
            );
        }

        return $result;
    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @return array
     * @throws \Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public static function getRevenueStats(\DateTime $startDate, \DateTime $endDate)
    {

        $result = array();
        $result['stats'] = array();
        $result['label'] = array();

        for ($day=0, $date = clone($startDate); $date <= $endDate; $date->add(new \DateInterval('P1D')), $day++) {
            $dayAmount = StatsOrderQuery::getSaleStats(
               $date->setTime(0,0,0),
               $date->setTime(23,59,59),
                false
            );
            $key = explode('-', $date->format('Y-m-d'));
            array_push($result['stats'], array($day, $dayAmount));
            array_push($result['label'], array($day,$key[2] . '/' . $key[1]));
        }

        return $result;
    }

    /**
     * @param \DateTime $startDate
     * @return array
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public static function getRevenueStatsByHours(\DateTime $startDate)
    {
        $result = array();
        $result['stats'] = array();
        $result['label'] = array();

        for ($hour = 0; $hour < 24; $hour++ ) {
            $dayAmount = OrderByHoursQuery::getSaleStats(
                clone ($startDate->setTime($hour,0,0)),
                clone($startDate->setTime($hour,59,59)),
                false
            );
            array_push($result['stats'], array($hour, $dayAmount));
            array_push($result['label'], array($hour, ($hour+1).'h' ));
        }

        return $result;
    }

    public static function getMonthlyOrdersStats(\DateTime $startDate, \DateTime $endDate)
    {
        $sql = "
            SELECT
            DATE(created_at) `date`,
            COUNT(DISTINCT id) total
            FROM `order`
            WHERE created_at >= '%startDate'
            AND
            created_at <= '%endDate'
            GROUP BY Date(created_at)
        ";

        $sql = str_replace(
            array('%startDate', '%endDate'),
            array($startDate->format(self::START_DAY_FORMAT), $endDate->format(self::END_DAY_FORMAT)),
            $sql
        );

        /** @var \Propel\Runtime\Connection\ConnectionWrapper $con */
        $con = Propel::getConnection(OrderTableMap::DATABASE_NAME);
        /** @var \Propel\Runtime\Connection\StatementWrapper $query */
        $query = $con->prepare($sql);
        $query->execute();

        return $query->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_UNIQUE);
    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param int $limit
     * @return \Thelia\Model\OrderProductQuery
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function bestSalesQuery(\DateTime $startDate, \DateTime $endDate, $limit = 20)
    {
        /** @var \Thelia\Model\OrderProductQuery $query */
        $query = OrderProductQuery::create()
            ->limit($limit)
            ->withColumn("SUM(" . OrderProductTableMap::QUANTITY . ")", "total_sold")
            ->withColumn(
                "SUM( IF(" . OrderProductTableMap::WAS_IN_PROMO . ',' . OrderProductTableMap::PROMO_PRICE . ',' . OrderProductTableMap::PRICE . ") * " . OrderProductTableMap::QUANTITY . ")",
                "total_ht"
            )
            ->addDescendingOrderByColumn("total_sold");

        $query->groupBy(OrderProductTableMap::PRODUCT_SALE_ELEMENTS_REF);

        // jointure de l'address de livraison pour le pays
        $query
            ->useOrderQuery()
            ->useOrderAddressRelatedByDeliveryOrderAddressIdQuery()
            ->endUse()
            ->endUse()
        ;

        // filter with status
        $query
            ->useOrderQuery()
            ->useOrderStatusQuery()
            ->filterById(explode(',',Statistic::getConfigValue('order_types')))
            ->endUse()
            ->endUse();

        // filtrage sur la date
        $query
            ->condition('start', OrderProductTableMap::CREATED_AT . ' >= ?', $startDate->setTime(0, 0))
            ->condition('end', OrderProductTableMap::CREATED_AT . ' <= ?', $endDate->setTime(23, 59, 59))
            ->where(array('start', 'end'), Criteria::LOGICAL_AND);

        // selection des données
        $query
            ->addAsColumn('title', OrderProductTableMap::TITLE)
            ->addAsColumn('product_ref', OrderProductTableMap::PRODUCT_REF)
            ->addAsColumn('pse_ref', OrderProductTableMap::PRODUCT_SALE_ELEMENTS_REF)
            ->addAsColumn('country', OrderAddressTableMap::COUNTRY_ID);
        $query->select(array(
            'title',
            'product_sale_elements_id',
            'product_ref',
            'pse_ref',
            'total_sold',
            'total_ht',
            'country'
        ));


        return $query;

    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @return CouponQuery
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function discountCodeQuery(\DateTime $startDate, \DateTime $endDate)
    {
        $query = CouponQuery::create();

        // Jointure sur order_coupon pour la date et le comptage
        $sql = "code
            AND
            order_coupon.created_at >= '%start'
            AND
            order_coupon.created_at <= '%end'";
        $sql = str_replace(
            array('%start', '%end'),
            array($startDate->format(self::START_DAY_FORMAT), $endDate->format(self::END_DAY_FORMAT)),
            $sql
        );
        $join = new Join();
        $join->addExplicitCondition('coupon', 'code', null, 'order_coupon', $sql);
        $join->setJoinType(Criteria::LEFT_JOIN);
        $query->addJoinObject($join);

        // Ajout du select
        $query
            ->addAsColumn('code', CouponTableMap::CODE)
            ->addAsColumn('type', CouponTableMap::TYPE)
            ->addAsColumn('rule', CouponTableMap::SERIALIZED_EFFECTS)
            ->addAsColumn('total', "COUNT(".OrderCouponTableMap::CODE.")");
        $query->groupBy(CouponTableMap::CODE)->orderBy('total', Criteria::DESC);
        $query->select(array(
            'code',
            'type',
            'rule',
            'total'
        ));

        return $query;
    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param $local
     * @return OrderQuery
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function meansTransportQuery(\DateTime $startDate, \DateTime $endDate, $local)
    {
        $query = OrderQuery::create();

        // filter with status
        $query->useOrderStatusQuery()
            ->filterById(explode(',',Statistic::getConfigValue('order_types')), Criteria::IN)
            ->endUse();

        // filtrage sur la date
        $query
            ->condition('start', OrderTableMap::CREATED_AT . ' >= ?', $startDate->setTime(0, 0))
            ->condition('end', OrderTableMap::CREATED_AT . ' <= ?', $endDate->setTime(23, 59, 59))
            ->where(array('start', 'end'), Criteria::LOGICAL_AND);

        // Jointure sur les modules de transport
        $query->useModuleRelatedByDeliveryModuleIdQuery()
            ->useI18nQuery($local)
            ->endUse()
            ->endUse();

        // select
        $query
            ->addAsColumn('code', ModuleTableMap::CODE)
            ->addAsColumn('title', ModuleI18nTableMap::TITLE)
            ->addAsColumn('total', 'COUNT(' . ModuleTableMap::CODE . ')');

        $query->groupBy('code');
        $query->select(array(
            'code',
            'title',
            'total'
        ));

        return $query;
    }

    /**
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param $local
     * @return OrderQuery
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function meansPaymentQuery(\DateTime $startDate, \DateTime $endDate, $local)
    {
        $query = OrderQuery::create();

        // filter with status
        $query->useOrderStatusQuery()
            ->filterById(explode(',',Statistic::getConfigValue('order_types')), Criteria::IN)
            ->endUse();

        // filtrage sur la date
        $query
            ->condition('start', OrderTableMap::CREATED_AT . ' >= ?', $startDate->setTime(0, 0))
            ->condition('end', OrderTableMap::CREATED_AT . ' <= ?', $endDate->setTime(23, 59, 59))
            ->where(array('start', 'end'), Criteria::LOGICAL_AND);

        // Jointure sur le module de payement
        $query
            ->useModuleRelatedByPaymentModuleIdQuery()
            ->useI18nQuery($local)
            ->endUse()
            ->endUse();

        // select
        $query
            ->addAsColumn('code', ModuleTableMap::CODE)
            ->addAsColumn('title', ModuleI18nTableMap::TITLE)
            ->addAsColumn('total', 'COUNT(' . ModuleTableMap::CODE . ')');

        $query->groupBy('code');
        $query->select(array(
            'code',
            'title',
            'total'
        ));

        return $query;
    }

    /**
     * @param $year
     * @return OrderQuery
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function turnoverQuery($year)
    {
        $query = OrderQuery::create();

        // filtrage sur la date
        $query
            ->filterByStatusId(explode(',',Statistic::getConfigValue('order_types')), Criteria::IN)
            ->where('YEAR(order.invoice_date) = ?', $year, \PDO::PARAM_STR);

        // jointure sur l'order product
        $orderTaxJoin = new Join();
        $orderTaxJoin->addExplicitCondition(
            OrderProductTableMap::TABLE_NAME,
            'ID',
            null,
            OrderProductTaxTableMap::TABLE_NAME,
            'ORDER_PRODUCT_ID',
            null
        );
        $orderTaxJoin->setJoinType(Criteria::LEFT_JOIN);
        $query
            ->innerJoinOrderProduct()
            ->addJoinObject($orderTaxJoin);


        // group by par mois
        $query->addGroupByColumn('YEAR(order.invoice_date)');
        $query->addGroupByColumn('MONTH(order.invoice_date)');


        // ajout des colonnes de compte
        $query
            ->withColumn(
                "SUM((`order_product`.QUANTITY * IF(`order_product`.WAS_IN_PROMO,`order_product`.PROMO_PRICE,`order_product`.PRICE)))",
                'TOTAL'
            )
            ->withColumn(
                "SUM((`order_product`.QUANTITY * IF(`order_product`.WAS_IN_PROMO,`order_product_tax`.PROMO_AMOUNT,`order_product_tax`.AMOUNT)))",
                'TAX'
            )
            ->addAsColumn('date', "CONCAT(YEAR(order.invoice_date),'-',MONTH(order.invoice_date))");


        $query->select(array(
            'date',
            'TOTAL',
            'TAX',
        ));

        return $query;
    }

    /**
     * @param $year
     * @return array
     * @throws \Exception
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function getTurnoverYear($year){

        $result =  $this->turnover($year);

        $table = array();
        $graph = array();
        $month = array();
        for ($i = 1; $i <= 12; ++$i) {
            $date = new \DateTime($year.'-'.$i);
            if(!isset($result[$date->format('Y-n')])){
                $table[$i] = array(
                    'TTCWithShippping' => 0,
                    'TTCWithoutShippping' => 0
                );
                $graph[] = array(
                    $i - 1,
                    0
                );
            }else{
                $tmp = $result[$date->format('Y-n')];

                //Get first day of month
                $startDate = new \DateTime($year . '-' . $i . '-01');
                /** @var \DateTime $endDate */

                //Get last day of month (first + total of month day -1)
                $endDate = clone($startDate);
                $endDate->add(new \DateInterval('P' . (cal_days_in_month(CAL_GREGORIAN, $i, $year)-1) . 'D'));

                $discount = OrderQuery::create()
                    ->filterByCreatedAt(sprintf("%s 00:00:00", $startDate->format('Y-m-d')), Criteria::GREATER_EQUAL)
                    ->filterByCreatedAt(sprintf("%s 23:59:59", $endDate->format('Y-m-d')), Criteria::LESS_EQUAL)
                    ->filterByStatusId(explode(',',Statistic::getConfigValue('order_types')), Criteria::IN)
                    ->withColumn("SUM(`order`.discount)", 'DISCOUNT')
                    ->select('DISCOUNT')->findOne();

                $postage = OrderQuery::create()
                    ->filterByCreatedAt(sprintf("%s 00:00:00", $startDate->format('Y-m-d')), Criteria::GREATER_EQUAL)
                    ->filterByCreatedAt(sprintf("%s 23:59:59", $endDate->format('Y-m-d')), Criteria::LESS_EQUAL)
                    ->filterByStatusId(explode(',',Statistic::getConfigValue('order_types')), Criteria::IN)
                    ->withColumn("SUM(`order`.postage)", 'POSTAGE')
                    ->select('POSTAGE')->findOne();

                if (null === $discount) {
                    $discount = 0;
                }

                // We want the HT turnover instead of TTC
                $table[$i] = array(
                    'TTCWithShippping' => round($tmp['TOTAL'] + $postage - $discount, 2), //round($tmp['TOTAL'] + $tmp['TAX'] + $postage - $discount, 2),
                    'TTCWithoutShippping' => round($tmp['TOTAL'] - $discount, 2) //round($tmp['TOTAL'] + $tmp['TAX'] - $discount, 2)
                );
                $graph[] = array(
                    $i - 1,
                    // We just want the HT turnover here
                    intval($tmp['TOTAL'] - $discount) //intval($tmp['TOTAL']+$tmp['TAX'] - $discount)
                );
            }
            $month[] = array($i-1,$date->format('M'));
            $table[$i]['month'] = $date->format('M');
        }
        $result['graph'] = $graph;
        $result['month'] = $month;
        $result['table'] = $table;
        return $result;
    }
}
